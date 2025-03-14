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

namespace Cecil\Generator;

use Cecil\Builder;
use Cecil\Collection\Page\Collection as PagesCollection;
use Cecil\Util;

/**
 * Abstract class AbstractGenerator.
 */
abstract class AbstractGenerator implements GeneratorInterface
{
    /** @var Builder */
    protected $builder;

    /** @var \Cecil\Config */
    protected $config;

    /** @var PagesCollection */
    protected $generatedPages;

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
        $this->config = $builder->getConfig();
        // Creates a new empty collection
        $this->generatedPages = new PagesCollection('generator-'.Util::formatClassName($this, ['lowercase' => true]));
    }

    /**
     * Run the `generate` method of the generator and returns pages.
     */
    public function runGenerate(): PagesCollection
    {
        $this->generate();

        // set default language (ex: "en") if necessary
        $this->generatedPages->map(function (\Cecil\Collection\Page\Page $page) {
            if ($page->getLanguage() === null) {
                $page->setLanguage($this->config->getLanguageDefault());
            }
        });

        return $this->generatedPages;
    }
}
