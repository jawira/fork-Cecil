<?php

declare(strict_types=1);

/*
 * This file is part of Cecil.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Assets;

use Cecil\Builder;
use Cecil\Collection\Page\Page;
use Cecil\Config;
use Cecil\Exception\RuntimeException;
use Cecil\Util;
use Intervention\Image\ImageManagerStatic as ImageManager;
use MatthiasMullie\Minify;
use ScssPhp\ScssPhp\Compiler;
use wapmorgan\Mp3Info\Mp3Info;

class Asset implements \ArrayAccess
{
    /** @var Builder */
    protected $builder;

    /** @var Config */
    protected $config;

    /** @var array */
    protected $data = [];

    /** @var bool */
    protected $fingerprinted = false;

    /** @var bool */
    protected $compiled = false;

    /** @var bool */
    protected $minified = false;

    /** @var bool */
    protected $optimize = false;
    protected $optimized = false;

    /** @var bool */
    protected $ignore_missing = false;

    /**
     * Creates an Asset from file(s) path.
     *
     * $options[
     *     'fingerprint'    => true,
     *     'minify'         => true,
     *     'filename'       => '',
     *     'ignore_missing' => false,
     * ];
     *
     * @param Builder      $builder
     * @param string|array $paths
     * @param array|null   $options
     *
     * @throws RuntimeException
     */
    public function __construct(Builder $builder, $paths, array $options = null)
    {
        $this->builder = $builder;
        $this->config = $builder->getConfig();
        $paths = is_array($paths) ? $paths : [$paths];
        array_walk($paths, function ($path) {
            if (empty($path)) {
                throw new RuntimeException('The path parameter of "asset() can\'t be empty."');
            }
        });
        $this->data = [
            'file'           => '', // absolute file path
            'filename'       => '', // filename
            'path_source'    => '', // public path to the file, before transformations
            'path'           => '', // public path to the file, after transformations
            'ext'            => '', // file extension
            'type'           => '', // file type (e.g.: image, audio, video, etc.)
            'subtype'        => '', // file media type (e.g.: image/png, audio/mp3, etc.)
            'size'           => 0,  // file size (in bytes)
            'content_source' => '', // file content, before transformations
            'content'        => '', // file content, after transformations
            'width'          => 0,  // width (in pixels) in case of an image
            'height'         => 0,  // height (in pixels) in case of an image
        ];

        // handles options
        $fingerprint = (bool) $this->config->get('assets.fingerprint.enabled');
        $minify = (bool) $this->config->get('assets.minify.enabled');
        $optimize = (bool) $this->config->get('assets.images.optimize.enabled');
        $filename = '';
        $ignore_missing = false;
        $force_slash = true;
        extract(is_array($options) ? $options : [], EXTR_IF_EXISTS);
        $this->ignore_missing = $ignore_missing;

        // fill data array with file(s) informations
        $cache = new Cache($this->builder, 'assets');
        $cacheKey = \sprintf('%s', implode('_', $paths));
        if (!$cache->has($cacheKey)) {
            $pathsCount = count($paths);
            $file = [];
            for ($i = 0; $i < $pathsCount; $i++) {
                // loads file(s)
                if (!$paths[$i]) {
                    throw new RuntimeException('The path parameter of asset() can\'t be empty.');
                }
                $file[$i] = $this->loadFile($paths[$i], $ignore_missing, $force_slash);
                // bundle: same type/ext only
                if ($i > 0) {
                    if ($file[$i]['type'] != $file[$i - 1]['type']) {
                        throw new RuntimeException(\sprintf('Asset bundle type error (%s != %s).', $file[$i]['type'], $file[$i - 1]['type']));
                    }
                    if ($file[$i]['ext'] != $file[$i - 1]['ext']) {
                        throw new RuntimeException(\sprintf('Asset bundle extension error (%s != %s).', $file[$i]['ext'], $file[$i - 1]['ext']));
                    }
                }
                // missing allowed = empty path
                if ($file[$i]['missing']) {
                    $this->data['path'] = '';

                    continue;
                }
                // set data
                $this->data['size'] += $file[$i]['size'];
                $this->data['content_source'] .= $file[$i]['content'];
                $this->data['content'] .= $file[$i]['content'];
                if ($i == 0) {
                    $this->data['file'] = $file[$i]['filepath']; // should be an array of files in case of bundle?
                    $this->data['filename'] = $file[$i]['path'];
                    $this->data['path_source'] = $file[$i]['path'];
                    $this->data['path'] = $file[$i]['path'];
                    if (!empty($filename)) { /** @phpstan-ignore-line */
                        $this->data['path'] = '/'.ltrim($filename, '/');
                    }
                    $this->data['ext'] = $file[$i]['ext'];
                    $this->data['type'] = $file[$i]['type'];
                    $this->data['subtype'] = $file[$i]['subtype'];
                    if ($this->data['type'] == 'image') {
                        $this->data['width'] = $this->getWidth();
                        $this->data['height'] = $this->getHeight();
                    }
                }
            }
            // bundle: define path
            if ($pathsCount > 1) {
                if (empty($filename)) { /** @phpstan-ignore-line */
                    switch ($this->data['ext']) {
                        case 'scss':
                        case 'css':
                            $this->data['path'] = '/styles.'.$file[0]['ext'];
                            break;
                        case 'js':
                            $this->data['path'] = '/scripts.'.$file[0]['ext'];
                            break;
                        default:
                            throw new RuntimeException(\sprintf('Asset bundle supports "%s" files only.', 'scss, css and js'));
                    }
                }
            }
            $cache->set($cacheKey, $this->data);
        }
        $this->data = $cache->get($cacheKey);

        // fingerprinting
        if ($fingerprint) {
            $this->fingerprint();
        }
        // compiling
        if ((bool) $this->config->get('assets.compile.enabled')) {
            $this->compile();
        }
        // minifying
        if ($minify) {
            $this->minify();
        }
        // optimizing
        if ($optimize) {
            $this->optimize = true;
        }
    }

    /**
     * Returns path.
     *
     * @throws RuntimeException
     */
    public function __toString(): string
    {
        try {
            $this->save();
        } catch (\Exception $e) {
            $this->builder->getLogger()->error($e->getMessage());
        }

        return $this->data['path'];
    }

    /**
     * Fingerprints a file.
     */
    public function fingerprint(): self
    {
        if ($this->fingerprinted) {
            return $this;
        }

        $fingerprint = hash('md5', $this->data['content_source']);
        $this->data['path'] = preg_replace(
            '/\.'.$this->data['ext'].'$/m',
            ".$fingerprint.".$this->data['ext'],
            $this->data['path']
        );

        $this->fingerprinted = true;

        return $this;
    }

    /**
     * Compiles a SCSS.
     *
     * @throws RuntimeException
     */
    public function compile(): self
    {
        if ($this->compiled) {
            return $this;
        }

        if ($this->data['ext'] != 'scss') {
            return $this;
        }

        $cache = new Cache($this->builder, 'assets');
        $cacheKey = $cache->createKeyFromAsset($this, ['compiled']);
        if (!$cache->has($cacheKey)) {
            $scssPhp = new Compiler();
            $importDir = [];
            $importDir[] = Util::joinPath($this->config->getStaticPath());
            $importDir[] = Util::joinPath($this->config->getAssetsPath());
            $scssDir = $this->config->get('assets.compile.import') ?? [];
            $themes = $this->config->getTheme() ?? [];
            foreach ($scssDir as $dir) {
                $importDir[] = Util::joinPath($this->config->getStaticPath(), $dir);
                $importDir[] = Util::joinPath($this->config->getAssetsPath(), $dir);
                $importDir[] = Util::joinPath(dirname($this->data['file']), $dir);
                foreach ($themes as $theme) {
                    $importDir[] = Util::joinPath($this->config->getThemeDirPath($theme, "static/$dir"));
                    $importDir[] = Util::joinPath($this->config->getThemeDirPath($theme, "assets/$dir"));
                }
            }
            $scssPhp->setImportPaths(array_unique($importDir));
            // source map
            if ($this->builder->isDebug() && (bool) $this->config->get('assets.compile.sourcemap')) {
                $importDir = [];
                $assetDir = (string) $this->config->get('assets.dir');
                $assetDirPos = strrpos($this->data['file'], DIRECTORY_SEPARATOR.$assetDir.DIRECTORY_SEPARATOR);
                $fileRelPath = substr($this->data['file'], $assetDirPos + 8);
                $filePath = Util::joinFile($this->config->getOutputPath(), $fileRelPath);
                $importDir[] = dirname($filePath);
                foreach ($scssDir as $dir) {
                    $importDir[] = Util::joinFile($this->config->getOutputPath(), $dir);
                }
                $scssPhp->setImportPaths(array_unique($importDir));
                $scssPhp->setSourceMap(Compiler::SOURCE_MAP_INLINE);
                $scssPhp->setSourceMapOptions([
                    'sourceMapBasepath' => Util::joinPath($this->config->getOutputPath()),
                    'sourceRoot'        => '/',
                ]);
            }
            // output style
            $outputStyles = ['expanded', 'compressed'];
            $outputStyle = strtolower((string) $this->config->get('assets.compile.style'));
            if (!in_array($outputStyle, $outputStyles)) {
                throw new RuntimeException(\sprintf('Scss output style "%s" doesn\'t exists.', $outputStyle));
            }
            $scssPhp->setOutputStyle($outputStyle);
            // variables
            $variables = $this->config->get('assets.compile.variables') ?? [];
            if (!empty($variables)) {
                $variables = array_map('ScssPhp\ScssPhp\ValueConverter::parseValue', $variables);
                $scssPhp->replaceVariables($variables);
            }
            // update data
            $this->data['path'] = preg_replace('/sass|scss/m', 'css', $this->data['path']);
            $this->data['ext'] = 'css';
            $this->data['content'] = $scssPhp->compileString($this->data['content'])->getCss();
            $this->compiled = true;
            $cache->set($cacheKey, $this->data);
        }
        $this->data = $cache->get($cacheKey);

        return $this;
    }

    /**
     * Minifying a CSS or a JS.
     *
     * @throws RuntimeException
     */
    public function minify(): self
    {
        // disable minify to preserve inline source map
        if ($this->builder->isDebug() && (bool) $this->config->get('assets.compile.sourcemap')) {
            return $this;
        }

        if ($this->minified) {
            return $this;
        }

        if ($this->data['ext'] == 'scss') {
            $this->compile();
        }

        if ($this->data['ext'] != 'css' && $this->data['ext'] != 'js') {
            return $this;
        }

        if (substr($this->data['path'], -8) == '.min.css' || substr($this->data['path'], -7) == '.min.js') {
            $this->minified;

            return $this;
        }

        $cache = new Cache($this->builder, 'assets');
        $cacheKey = $cache->createKeyFromAsset($this, ['minified']);
        if (!$cache->has($cacheKey)) {
            switch ($this->data['ext']) {
                case 'css':
                    $minifier = new Minify\CSS($this->data['content']);
                    break;
                case 'js':
                    $minifier = new Minify\JS($this->data['content']);
                    break;
                default:
                    throw new RuntimeException(\sprintf('Not able to minify "%s"', $this->data['path']));
            }
            $this->data['path'] = preg_replace(
                '/\.'.$this->data['ext'].'$/m',
                '.min.'.$this->data['ext'],
                $this->data['path']
            );
            $this->data['content'] = $minifier->minify();
            $this->minified = true;
            $cache->set($cacheKey, $this->data);
        }
        $this->data = $cache->get($cacheKey);

        return $this;
    }

    /**
     * Optimizing an image.
     */
    public function optimize(string $filepath): self
    {
        if ($this->data['type'] != 'image') {
            return $this;
        }

        $cache = new Cache($this->builder, 'assets');
        $tags = ['optimized'];
        if ($this->data['width']) {
            array_unshift($tags, "{$this->data['width']}x");
        }
        $cacheKey = $cache->createKeyFromAsset($this, $tags);
        if (!$cache->has($cacheKey)) {
            $message = $this->data['path'];
            $sizeBefore = filesize($filepath);
            Image::optimizer($this->config->get('assets.images.quality') ?? 75)->optimize($filepath);
            $sizeAfter = filesize($filepath);
            if ($sizeAfter < $sizeBefore) {
                $message = \sprintf(
                    '%s (%s Ko -> %s Ko)',
                    $message,
                    ceil($sizeBefore / 1000),
                    ceil($sizeAfter / 1000)
                );
            }
            $this->data['content'] = Util\File::fileGetContents($filepath);
            $cache->set($cacheKey, $this->data);
            $this->builder->getLogger()->debug(\sprintf('Asset "%s" optimized', $message));
        }
        $this->data = $cache->get($cacheKey);
        $this->optimized = true;

        return $this;
    }

    /**
     * Resizes an image with a new $width.
     *
     * @throws RuntimeException
     */
    public function resize(int $width): self
    {
        if ($this->data['type'] != 'image') {
            throw new RuntimeException(\sprintf('Not able to resize "%s": it\'s not an image', $this->data['path']));
        }

        if ($width >= $this->getWidth()) {
            return $this;
        }

        $assetResized = clone $this;
        $assetResized->data['width'] = $width;

        $cache = new Cache($this->builder, 'assets');
        $cacheKey = $cache->createKeyFromAsset($assetResized, ["{$width}x"]);
        if (!$cache->has($cacheKey)) {
            if ($assetResized->data['type'] !== 'image') {
                throw new RuntimeException(\sprintf('Not able to resize "%s"', $assetResized->data['path']));
            }
            if (!extension_loaded('gd')) {
                throw new RuntimeException('GD extension is required to use images resize.');
            }

            try {
                $img = ImageManager::make($assetResized->data['content_source']);
                $img->resize($width, null, function (\Intervention\Image\Constraint $constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            } catch (\Exception $e) {
                throw new RuntimeException(\sprintf('Not able to resize image "%s": %s', $assetResized->data['path'], $e->getMessage()));
            }
            $assetResized->data['path'] = '/'.Util::joinPath((string) $this->config->get('assets.target'), 'thumbnails', (string) $width, $assetResized->data['path']);

            try {
                $assetResized->data['content'] = (string) $img->encode($assetResized->data['ext'], $this->config->get('assets.images.quality'));
                $assetResized->data['height'] = $assetResized->getHeight();
            } catch (\Exception $e) {
                throw new RuntimeException(\sprintf('Not able to encode image "%s": %s', $assetResized->data['path'], $e->getMessage()));
            }

            $cache->set($cacheKey, $assetResized->data);
        }
        $assetResized->data = $cache->get($cacheKey);

        return $assetResized;
    }

    /**
     * Returns the data URL of an image.
     *
     * @throws RuntimeException
     */
    public function dataurl(): string
    {
        if ($this->data['type'] !== 'image') {
            throw new RuntimeException(\sprintf('Can\'t get data URL of "%s"', $this->data['path']));
        }

        return (string) ImageManager::make($this->data['content'])->encode('data-url', $this->config->get('assets.images.quality'));
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!is_null($offset)) {
            $this->data[$offset] = $value;
        }
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return isset($this->data[$offset]) ? $this->data[$offset] : null;
    }

    /**
     * Hashing content of an asset with the specified algo, sha384 by default.
     * Used for SRI (Subresource Integrity).
     *
     * @see https://developer.mozilla.org/fr/docs/Web/Security/Subresource_Integrity
     */
    public function getIntegrity(string $algo = 'sha384'): string
    {
        return \sprintf('%s-%s', $algo, base64_encode(hash($algo, $this->data['content'], true)));
    }

    /**
     * Returns the width of an image/SVG.
     *
     * @throws RuntimeException
     */
    public function getWidth(): int
    {
        if ($this->data['type'] != 'image') {
            return 0;
        }
        if ($this->isSVG() && false !== $svg = $this->getSvgAttributes()) {
            return (int) $svg->width;
        }
        if (false === $size = $this->getImageSize()) {
            throw new RuntimeException(\sprintf('Not able to get width of "%s"', $this->data['path']));
        }

        return $size[0];
    }

    /**
     * Returns the height of an image/SVG.
     *
     * @throws RuntimeException
     */
    public function getHeight(): int
    {
        if ($this->data['type'] != 'image') {
            return 0;
        }
        if ($this->isSVG() && false !== $svg = $this->getSvgAttributes()) {
            return (int) $svg->height;
        }
        if (false === $size = $this->getImageSize()) {
            throw new RuntimeException(\sprintf('Not able to get height of "%s"', $this->data['path']));
        }

        return $size[1];
    }

    /**
     * Returns MP3 file infos.
     *
     * @see https://github.com/wapmorgan/Mp3Info
     */
    public function getAudio(): Mp3Info
    {
        if ($this->data['type'] !== 'audio') {
            throw new RuntimeException(\sprintf('Not able to get audio infos of "%s"', $this->data['path']));
        }

        return new Mp3Info($this->data['file']);
    }

    /**
     * Saves file.
     * Note: a file from `static/` with the same name will NOT be overridden.
     *
     * @throws RuntimeException
     */
    public function save(): void
    {
        $filepath = Util::joinFile($this->config->getOutputPath(), $this->data['path']);
        if (!$this->builder->getBuildOptions()['dry-run'] && !Util\File::getFS()->exists($filepath)) {
            try {
                Util\File::getFS()->dumpFile($filepath, $this->data['content']);
                $this->builder->getLogger()->debug(\sprintf('Asset "%s" saved', $this->data['path']));
                if ($this->optimize) {
                    $this->optimize($filepath);
                }
            } catch (\Symfony\Component\Filesystem\Exception\IOException $e) {
                if (!$this->ignore_missing) {
                    throw new RuntimeException(\sprintf('Can\'t save asset "%s".', $filepath));
                }
            }
        }
    }

    /**
     * Load file data.
     *
     * @throws RuntimeException
     */
    private function loadFile(string $path, bool $ignore_missing = false, bool $force_slash = true): array
    {
        $file = [];

        if (false === $filePath = $this->findFile($path)) {
            if ($ignore_missing) {
                $file['missing'] = true;

                return $file;
            }

            throw new RuntimeException(\sprintf('Asset file "%s" doesn\'t exist.', $path));
        }

        if (Util\Url::isUrl($path)) {
            $urlHost = parse_url($path, PHP_URL_HOST);
            $urlPath = parse_url($path, PHP_URL_PATH);
            $urlQuery = parse_url($path, PHP_URL_QUERY);
            $path = Util::joinPath((string) $this->config->get('assets.target'), $urlHost, $urlPath);
            $path = $this->sanitize($path);
            if (!empty($urlQuery)) {
                $path = Util::joinPath($path, Page::slugify($urlQuery));
                // Google Fonts hack
                if (strpos($urlPath, '/css') !== false) {
                    $path .= '.css';
                }
            }
            $force_slash = true;
        }
        if ($force_slash) {
            $path = '/'.ltrim($path, '/');
        }

        $pathinfo = pathinfo($path);
        list($type, $subtype) = Util\File::getMimeType($filePath);
        $content = Util\File::fileGetContents($filePath);

        $file['filepath'] = $filePath;
        $file['path'] = $path;
        $file['ext'] = $pathinfo['extension'] ?? '';
        $file['type'] = $type;
        $file['subtype'] = $subtype;
        $file['size'] = filesize($filePath);
        $file['content'] = $content;
        $file['missing'] = false;

        return $file;
    }

    /**
     * Try to find the file:
     *   1. remote (if $path is a valid URL)
     *   2. in static/
     *   3. in themes/<theme>/static/
     * Returns local file path or false if file don't exists.
     *
     * @throws RuntimeException
     *
     * @return string|false
     */
    private function findFile(string $path)
    {
        // in case of remote file: save it and returns cached file path
        if (Util\Url::isUrl($path)) {
            $url = $path;
            $relativePath = Page::slugify(\sprintf('%s%s-%s', parse_url($url, PHP_URL_HOST), parse_url($url, PHP_URL_PATH), parse_url($url, PHP_URL_QUERY)));
            $filePath = Util::joinFile($this->config->getCacheAssetsPath(), $relativePath);
            if (!file_exists($filePath)) {
                if (!Util\Url::isRemoteFileExists($url)) {
                    return false;
                }
                if (false === $content = Util\File::fileGetContents($url, true)) {
                    return false;
                }
                if (strlen($content) <= 1) {
                    throw new RuntimeException(\sprintf('Asset at "%s" is empty.', $url));
                }
                Util\File::getFS()->dumpFile($filePath, $content);
            }

            return $filePath;
        }

        // checks in assets/
        $filePath = Util::joinFile($this->config->getAssetsPath(), $path);
        if (Util\File::getFS()->exists($filePath)) {
            return $filePath;
        }

        // checks in each themes/<theme>/assets/
        foreach ($this->config->getTheme() as $theme) {
            $filePath = Util::joinFile($this->config->getThemeDirPath($theme, 'assets'), $path);
            if (Util\File::getFS()->exists($filePath)) {
                return $filePath;
            }
        }

        // checks in static/
        $filePath = Util::joinFile($this->config->getStaticTargetPath(), $path);
        if (Util\File::getFS()->exists($filePath)) {
            return $filePath;
        }

        // checks in each themes/<theme>/static/
        foreach ($this->config->getTheme() as $theme) {
            $filePath = Util::joinFile($this->config->getThemeDirPath($theme, 'static'), $path);
            if (Util\File::getFS()->exists($filePath)) {
                return $filePath;
            }
        }

        return false;
    }

    /**
     * Returns image size informations.
     *
     * @see https://www.php.net/manual/function.getimagesize.php
     *
     * @return array|false
     */
    private function getImageSize()
    {
        if (!$this->data['type'] == 'image') {
            return false;
        }

        try {
            if (false === $size = getimagesizefromstring($this->data['content'])) {
                return false;
            }
        } catch (\Exception $e) {
            throw new RuntimeException(\sprintf('Handling asset "%s" failed: "%s"', $this->data['path_source'], $e->getMessage()));
        }

        return $size;
    }

    /**
     * Returns true if asset is a SVG.
     */
    private function isSVG(): bool
    {
        return in_array($this->data['subtype'], ['image/svg', 'image/svg+xml']) || $this->data['ext'] == 'svg';
    }

    /**
     * Returns SVG attributes.
     *
     * @return \SimpleXMLElement|false
     */
    private function getSvgAttributes()
    {
        if (false === $xml = simplexml_load_string($this->data['content_source'])) {
            return false;
        }

        return $xml->attributes();
    }

    /**
     * Replaces some characters by '_'.
     */
    private function sanitize(string $string): string
    {
        return str_replace(['<', '>', ':', '"', '\\', '|', '?', '*'], '_', $string);
    }
}
