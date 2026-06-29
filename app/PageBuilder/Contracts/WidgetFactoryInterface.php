<?php

declare(strict_types=1);

namespace App\PageBuilder\Contracts;

interface WidgetFactoryInterface
{
    public function make(string $type): WidgetInterface;

    public function canMake(string $type): bool;
}