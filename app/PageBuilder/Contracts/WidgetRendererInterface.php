<?php

declare(strict_types=1);

namespace App\PageBuilder\Contracts;

use App\PageBuilder\Foundation\WidgetContext;

interface WidgetRendererInterface
{
    public function render(
        string $widgetType,
        array $settings = [],
        ?WidgetContext $context = null
    ): string;

    public function renderNode(
        array $node,
        ?WidgetContext $context = null
    ): string;
}