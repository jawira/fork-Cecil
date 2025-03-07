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

namespace Cecil\Step\Pages;

use Cecil\Collection\Page\Page;
use Cecil\Converter\Converter;
use Cecil\Exception\RuntimeException;
use Cecil\Step\AbstractStep;

/**
 * Converts content of all pages.
 */
class Convert extends AbstractStep
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        if ($this->builder->getBuildOptions()['drafts']) {
            return 'Converting pages (drafts included)';
        }

        return 'Converting pages';
    }

    /**
     * {@inheritdoc}
     */
    public function init(array $options): void
    {
        parent::init($options);

        if (is_dir($this->builder->getConfig()->getPagesPath())) {
            $this->canProcess = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function process(): void
    {
        if (count($this->builder->getPages()) == 0) {
            return;
        }

        $max = count($this->builder->getPages());
        $count = 0;
        /** @var Page $page */
        foreach ($this->builder->getPages() as $page) {
            if (!$page->isVirtual()) {
                $count++;

                try {
                    $convertedPage = $this->convertPage($page, (string) $this->config->get('frontmatter.format'));
                    // set default language (ex: "en") if necessary
                    if ($convertedPage->getLanguage() === null) {
                        $convertedPage->setLanguage($this->config->getLanguageDefault());
                    }
                } catch (RuntimeException $e) {
                    $this->builder->getLogger()->error(sprintf('Unable to convert "%s:%s": %s', $e->getPageFile(), $e->getPageLine(), $e->getMessage()));
                    $this->builder->getPages()->remove($page->getId());
                    continue;
                } catch (\Exception $e) {
                    $this->builder->getLogger()->error(sprintf('Unable to convert "%s": %s', $page->getFilePath(), $e->getMessage()));
                    $this->builder->getPages()->remove($page->getId());
                    continue;
                }

                /**
                 * Apply a custom path to pages of a specified section.
                 *
                 * ie:
                 * paths:
                 * - section: Blog
                 *   path: :section/:year/:month/:day/:slug
                 */
                if (is_array($this->config->get('paths'))) {
                    foreach ($this->config->get('paths') as $entry) {
                        if (isset($entry['section'])) {
                            /** @var Page $page */
                            if ($page->getSection() == Page::slugify($entry['section'])) {
                                if (isset($entry['path'])) {
                                    $path = str_replace(
                                        [
                                            ':year',
                                            ':month',
                                            ':day',
                                            ':section',
                                            ':slug',
                                        ],
                                        [
                                            $page->getVariable('date')->format('Y'),
                                            $page->getVariable('date')->format('m'),
                                            $page->getVariable('date')->format('d'),
                                            $page->getSection(),
                                            $page->getSlug(),
                                        ],
                                        $entry['path']
                                    );
                                    $page->setPath($path);
                                }
                            }
                        }
                    }
                }

                $message = \sprintf('Page "%s" converted', $page->getId());
                // forces drafts convert?
                if ($this->builder->getBuildOptions()['drafts']) {
                    $page->setVariable('published', true);
                }
                if ($page->getVariable('published')) {
                    $this->builder->getPages()->replace($page->getId(), $convertedPage);
                } else {
                    $message .= ' (not published)';
                }
                $this->builder->getLogger()->info($message, ['progress' => [$count, $max]]);
            }
        }
    }

    /**
     * Converts page content:
     * - Yaml frontmatter to PHP array
     * - Markdown body to HTML.
     *
     * @throws RuntimeException
     */
    public function convertPage(Page $page, $format = 'yaml'): Page
    {
        $converter = new Converter($this->builder);
        // converts frontmatter
        if ($page->getFrontmatter()) {
            try {
                $variables = $converter->convertFrontmatter($page->getFrontmatter(), $format);
            } catch (RuntimeException $e) {
                throw new RuntimeException($e->getMessage(), $page->getFilePath(), $e->getPageLine());
            }
            $page->setFmVariables($variables);
            $page->setVariables($variables);
        }

        // converts body only if page is published
        if ($page->getVariable('published') || $this->options['drafts']) {
            try {
                $html = $converter->convertBody($page->getBody());
            } catch (RuntimeException $e) {
                throw new \Exception($e->getMessage());
            }
            $page->setBodyHtml($html);
        }

        return $page;
    }
}
