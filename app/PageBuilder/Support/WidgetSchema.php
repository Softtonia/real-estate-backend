<?php

namespace App\PageBuilder\Support;

class WidgetSchema
{
    protected array $fields = [];

    public function add(array $field): static
    {
        $this->fields[] = $field;

        return $this;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function toArray(): array
    {
        return $this->fields;
    }
}