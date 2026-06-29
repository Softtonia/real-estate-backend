<?php

declare(strict_types=1);

namespace App\PageBuilder\Contracts;

use App\PageBuilder\Foundation\WidgetContext;

interface DynamicResolverInterface
{
    public function availableFields(?int $postTypeId = null, array $context = []): array;

    public function resolveField(
        string $fieldKey,
        WidgetContext $context,
        mixed $default = null
    ): mixed;

    public function resolveMany(
        array $fieldKeys,
        WidgetContext $context
    ): array;
}