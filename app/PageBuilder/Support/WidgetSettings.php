<?php

namespace App\PageBuilder\Support;

class WidgetSettings
{
    public function __construct(
        protected array $settings = []
    ) {}

    public function all(): array
    {
        return $this->settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function has(string $key): bool
    {
        return data_get($this->settings, $key) !== null;
    }

    public function set(string $key, mixed $value): static
    {
        data_set($this->settings, $key, $value);

        return $this;
    }

    public function merge(array $settings): static
    {
        $this->settings = array_replace_recursive(
            $this->settings,
            $settings
        );

        return $this;
    }

    public function toArray(): array
    {
        return $this->settings;
    }
}