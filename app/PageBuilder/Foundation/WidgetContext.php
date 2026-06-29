<?php

declare(strict_types=1);

namespace App\PageBuilder\Foundation;

class WidgetContext
{
    public function __construct(
        protected ?object $post = null,
        protected ?object $postType = null,
        protected ?int $postTypeId = null,
        protected array $fields = [],
        protected array $taxonomies = [],
        protected array $terms = [],
        protected array $requestData = [],
        protected string $mode = 'frontend'
    ) {
    }

    public static function empty(): static
    {
        return new static();
    }

    public static function preview(array $data = []): static
    {
        return new static(
            post: $data['post'] ?? null,
            postType: $data['post_type'] ?? null,
            postTypeId: $data['post_type_id'] ?? null,
            fields: $data['fields'] ?? [],
            taxonomies: $data['taxonomies'] ?? [],
            terms: $data['terms'] ?? [],
            requestData: $data['request'] ?? [],
            mode: 'preview'
        );
    }

    public function post(): ?object
    {
        return $this->post;
    }

    public function postType(): ?object
    {
        return $this->postType;
    }

    public function postTypeId(): ?int
    {
        return $this->postTypeId;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function taxonomies(): array
    {
        return $this->taxonomies;
    }

    public function terms(): array
    {
        return $this->terms;
    }

    public function requestData(): array
    {
        return $this->requestData;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isPreview(): bool
    {
        return $this->mode === 'preview';
    }

    public function isFrontend(): bool
    {
        return $this->mode === 'frontend';
    }

    public function field(string $key, mixed $default = null): mixed
    {
        return data_get($this->fields, $key, $default);
    }

    public function request(string $key, mixed $default = null): mixed
    {
        return data_get($this->requestData, $key, $default);
    }

    public function withFields(array $fields): static
    {
        $clone = clone $this;
        $clone->fields = array_replace_recursive($this->fields, $fields);

        return $clone;
    }

    public function withRequestData(array $requestData): static
    {
        $clone = clone $this;
        $clone->requestData = array_replace_recursive($this->requestData, $requestData);

        return $clone;
    }

    public function toArray(): array
    {
        return [
            'post_type_id' => $this->postTypeId,
            'fields' => $this->fields,
            'taxonomies' => $this->taxonomies,
            'terms' => $this->terms,
            'request' => $this->requestData,
            'mode' => $this->mode,
        ];
    }
}