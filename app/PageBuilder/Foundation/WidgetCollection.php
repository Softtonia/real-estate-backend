<?php

declare(strict_types=1);

namespace App\PageBuilder\Foundation;

use App\PageBuilder\Contracts\WidgetInterface;
use Illuminate\Support\Collection;

class WidgetCollection extends Collection
{
    public function findByType(string $type): ?WidgetInterface
    {
        return $this->first(
            fn(WidgetInterface $widget) => $widget->getType() === $type
        );
    }

    public function categories(): array
    {
        return $this
            ->map(fn(WidgetInterface $widget) => $widget->getCategory())
            ->unique()
            ->values()
            ->all();
    }

    public function toApiArray(): array
    {
        return $this
            ->map(function (WidgetInterface $widget) {
                return [
                    'type' => $widget->getType(),
                    'name' => $widget->getName(),
                    'category' => $widget->getCategory(),
                    'icon' => $widget->getIcon(),
                    'description' => $widget->getDescription(),
                    'supports_dynamic_data' => $widget->supportsDynamicData(),
                    'default_settings' => $widget->defaultSettings(),
                    'schema' => $widget->schema(),
                    'style_defaults' => method_exists($widget, 'styleDefaults')
                        ? $widget->styleDefaults()
                        : [],
                    'style_schema' => method_exists($widget, 'styleSchema')
                        ? $widget->styleSchema()
                        : [],
                    'assets' => $widget->assets(),
                ];
            })
            ->values()
            ->all();
    }
}
