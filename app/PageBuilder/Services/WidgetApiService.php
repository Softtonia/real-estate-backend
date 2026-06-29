<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\PageBuilder\Foundation\WidgetManager;

class WidgetApiService
{
    public function __construct(
        protected WidgetManager $widgetManager
    ) {
    }

    public function getWidgets(): array
    {
        $widgets = $this->widgetManager->toApiArray();

        return [
            'widgets' => $widgets,
            'categories' => $this->widgetManager->all()->categories(),
            'total' => count($widgets),
        ];
    }

    public function getWidget(string $type): array
    {
        $widget = $this->widgetManager->get($type);

        return [
            'type' => $widget->getType(),
            'name' => $widget->getName(),
            'category' => $widget->getCategory(),
            'icon' => $widget->getIcon(),
            'description' => $widget->getDescription(),
            'supports_dynamic_data' => $widget->supportsDynamicData(),
            'default_settings' => $widget->defaultSettings(),
            'schema' => $widget->schema(),
            'assets' => $widget->assets(),
        ];
    }
}