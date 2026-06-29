<?php

declare(strict_types=1);

namespace App\PageBuilder\Foundation;

use App\PageBuilder\Contracts\WidgetFactoryInterface;
use App\PageBuilder\Contracts\WidgetInterface;
use App\PageBuilder\Contracts\WidgetRendererInterface;
use InvalidArgumentException;

class WidgetManager implements WidgetRendererInterface
{
    public function __construct(
        protected WidgetRegistry $registry,
        protected WidgetFactoryInterface $factory
    ) {
    }

    public function register(string $widgetClass): static
    {
        $this->registry->register($widgetClass);

        return $this;
    }

    public function registerMany(array $widgets): static
    {
        $this->registry->registerMany($widgets);

        return $this;
    }

    public function has(string $type): bool
    {
        return $this->registry->has($type);
    }

    public function get(string $type): WidgetInterface
    {
        return $this->factory->make($type);
    }

    public function all(): WidgetCollection
    {
        $widgets = [];

        foreach ($this->registry->types() as $type) {
            $widgets[] = $this->factory->make($type);
        }

        return new WidgetCollection($widgets);
    }

    public function toApiArray(): array
    {
        return $this->all()->toApiArray();
    }

    public function schema(string $type): array
    {
        return $this->get($type)->schema();
    }

    public function defaultSettings(string $type): array
    {
        return $this->get($type)->defaultSettings();
    }

    public function validateSettings(string $type, array $settings): array
    {
        return $this->get($type)->validateSettings($settings);
    }

    public function render(
        string $widgetType,
        array $settings = [],
        ?WidgetContext $context = null
    ): string {
        $widget = $this->get($widgetType);

        $context ??= WidgetContext::empty();

        $validatedSettings = $widget->validateSettings($settings);

        return $widget->render($validatedSettings, $context);
    }

    public function renderNode(
        array $node,
        ?WidgetContext $context = null
    ): string {
        $type = $node['type'] ?? $node['widget'] ?? null;

        if (! $type) {
            throw new InvalidArgumentException('Widget node must contain type or widget key.');
        }

        $settings = $node['settings'] ?? [];

        if (! is_array($settings)) {
            throw new InvalidArgumentException('Widget node settings must be an array.');
        }

        return $this->render($type, $settings, $context);
    }
}