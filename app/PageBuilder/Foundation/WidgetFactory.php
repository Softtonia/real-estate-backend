<?php

declare(strict_types=1);

namespace App\PageBuilder\Foundation;

use App\PageBuilder\Contracts\WidgetFactoryInterface;
use App\PageBuilder\Contracts\WidgetInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class WidgetFactory implements WidgetFactoryInterface
{
    public function __construct(
        protected WidgetRegistry $registry,
        protected Container $container
    ) {
    }

    public function make(string $type): WidgetInterface
    {
        $widgetClass = $this->registry->get($type);

        if (! $widgetClass) {
            throw new InvalidArgumentException("Widget type [{$type}] is not registered.");
        }

        $widget = $this->container->make($widgetClass);

        if (! $widget instanceof WidgetInterface) {
            throw new InvalidArgumentException(
                "Widget class [{$widgetClass}] must implement WidgetInterface."
            );
        }

        return $widget;
    }

    public function canMake(string $type): bool
    {
        return $this->registry->has($type);
    }
}