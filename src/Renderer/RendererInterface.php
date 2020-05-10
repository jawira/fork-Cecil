<?php
/**
 * This file is part of the Cecil/Cecil package.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Renderer;

use Cecil\Builder;

/**
 * Interface RendererInterface.
 */
interface RendererInterface
{
    /**
     * @param Builder      $buider
     * @param string|array $templatesPath
     */
    public function __construct(Builder $buider, $templatesPath);

    /**
     * Adds a global variable.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return void
     */
    public function addGlobal(string $name, $value): void;

    /**
     * Rendering.
     *
     * @param string $template
     * @param array  $variables
     *
     * @return string
     */
    public function render(string $template, array $variables): string;
}
