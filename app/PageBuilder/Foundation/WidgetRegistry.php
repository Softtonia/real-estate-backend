<?php

declare(strict_types=1);

namespace App\PageBuilder\Foundation;

use App\PageBuilder\Contracts\WidgetInterface;
use InvalidArgumentException;

class WidgetRegistry
{
    /**
     * @var array<string, class-string<WidgetInterface>>
     */
    protected array $widgets = [];

    /**
     * @param class-string<WidgetInterface> $widgetClass
     */
    public function register(string $widgetClass): static
    {
        $this->validateWidgetClass($widgetClass);

        $type = $widgetClass::type();

        $this->validateWidgetType($type, $widgetClass);

        if ($this->has($type) && $this->widgets[$type] !== $widgetClass) {
            throw new InvalidArgumentException(
                "Widget type [{$type}] is already registered with [{$this->widgets[$type]}]."
            );
        }

        $this->widgets[$type] = $widgetClass;

        return $this;
    }

    /**
     * @param array<int, class-string<WidgetInterface>> $widgets
     */
    public function registerMany(array $widgets): static
    {
        foreach ($widgets as $widgetClass) {
            $this->register($widgetClass);
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->widgets);
    }

    /**
     * @return class-string<WidgetInterface>|null
     */
    public function get(string $type): ?string
    {
        return $this->widgets[$type] ?? null;
    }

    /**
     * @return class-string<WidgetInterface>
     */
    public function getOrFail(string $type): string
    {
        if (! $this->has($type)) {
            throw new InvalidArgumentException("Widget type [{$type}] is not registered.");
        }

        return $this->widgets[$type];
    }

    /**
     * @return array<string, class-string<WidgetInterface>>
     */
    public function all(): array
    {
        return $this->widgets;
    }

    public function types(): array
    {
        return array_keys($this->widgets);
    }

    public function unregister(string $type): static
    {
        unset($this->widgets[$type]);

        return $this;
    }

    public function clear(): static
    {
        $this->widgets = [];

        return $this;
    }

    public function count(): int
    {
        return count($this->widgets);
    }

    protected function validateWidgetClass(string $widgetClass): void
    {
        if (! class_exists($widgetClass)) {
            throw new InvalidArgumentException("Widget class [{$widgetClass}] does not exist.");
        }

        if (! is_subclass_of($widgetClass, WidgetInterface::class)) {
            throw new InvalidArgumentException(
                "Widget class [{$widgetClass}] must implement [".WidgetInterface::class."]."
            );
        }

        if (! method_exists($widgetClass, 'type')) {
            throw new InvalidArgumentException(
                "Widget class [{$widgetClass}] must define static type() method."
            );
        }
    }

    protected function validateWidgetType(string $type, string $widgetClass): void
    {
        if (trim($type) === '') {
            throw new InvalidArgumentException("Widget class [{$widgetClass}] returned empty type.");
        }

        if (! preg_match('/^[a-z0-9_\-]+$/', $type)) {
            throw new InvalidArgumentException(
                "Widget type [{$type}] is invalid. Use lowercase letters, numbers, hyphen or underscore."
            );
        }
    }
}