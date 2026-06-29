<?php

namespace App\PageBuilder\Support;

class RenderContext
{
    public function __construct(
        protected array $options = []
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }

    public function options(): array
    {
        return $this->options;
    }
}