<?php

declare(strict_types=1);

namespace App\PageBuilder\Contracts;

use App\PageBuilder\Foundation\WidgetContext;

interface WidgetInterface
{
    public static function type(): string;

    public function getType(): string;

    public function getName(): string;

    public function getCategory(): string;

    public function getIcon(): string;

    public function getDescription(): string;

    public function supportsDynamicData(): bool;

    public function defaultSettings(): array;

    public function schema(): array;

    public function validateSettings(array $settings): array;

    public function assets(): array;

    public function render(array $settings, WidgetContext $context): string;
}