<?php

namespace App\PageBuilder\Support;

class DynamicContext
{
    public function __construct(
        protected ?object $post = null,
        protected ?object $postType = null,
        protected array $taxonomies = [],
        protected array $fields = []
    ) {}

    public function post(): ?object
    {
        return $this->post;
    }

    public function postType(): ?object
    {
        return $this->postType;
    }

    public function taxonomies(): array
    {
        return $this->taxonomies;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $key, mixed $default = null): mixed
    {
        return data_get($this->fields, $key, $default);
    }
}