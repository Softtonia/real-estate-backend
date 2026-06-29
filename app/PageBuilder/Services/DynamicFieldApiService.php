<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\PageBuilder\Contracts\DynamicResolverInterface;

class DynamicFieldApiService
{
    public function __construct(
        protected DynamicResolverInterface $dynamicResolver
    ) {
    }

    public function getFields(?int $postTypeId = null, array $context = []): array
    {
        return $this->dynamicResolver->availableFields($postTypeId, $context);
    }
}