<?php

namespace App\PageBuilder\Support;

class DynamicField
{
    public function __construct(
        protected string $key,
        protected string $label,
        protected string $type,
        protected array $meta = []
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'meta' => $this->meta,
        ];
    }
}