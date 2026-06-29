<?php

declare(strict_types=1);

namespace App\PageBuilder\Providers;

use Illuminate\Support\ServiceProvider;
use App\PageBuilder\Contracts\DynamicResolverInterface;
use App\PageBuilder\Contracts\WidgetFactoryInterface;
use App\PageBuilder\Contracts\WidgetRendererInterface;
use App\PageBuilder\DynamicData\DynamicFieldResolver;
use App\PageBuilder\Foundation\WidgetFactory;
use App\PageBuilder\Foundation\WidgetManager;
use App\PageBuilder\Foundation\WidgetRegistry;
use App\PageBuilder\Widgets\ButtonWidget;
use App\PageBuilder\Widgets\GalleryWidget;
use App\PageBuilder\Widgets\HeadingWidget;
use App\PageBuilder\Widgets\HtmlWidget;
use App\PageBuilder\Widgets\ImageWidget;
use App\PageBuilder\Widgets\RepeaterWidget;
use App\PageBuilder\Widgets\TaxonomyTermsWidget;
use App\PageBuilder\Widgets\TextWidget;

class PageBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetRegistry::class, function () {
            return new WidgetRegistry();
        });

        $this->app->singleton(WidgetFactoryInterface::class, function ($app) {
            return new WidgetFactory(
                $app->make(WidgetRegistry::class),
                $app
            );
        });

        $this->app->singleton(WidgetManager::class, function ($app) {
            return new WidgetManager(
                $app->make(WidgetRegistry::class),
                $app->make(WidgetFactoryInterface::class)
            );
        });

        $this->app->singleton(DynamicResolverInterface::class, function () {
            return new DynamicFieldResolver();
        });

        $this->app->alias(WidgetManager::class, WidgetRendererInterface::class);
    }

    public function boot(): void
    {
        app(WidgetManager::class)->registerMany([
            HeadingWidget::class,
            TextWidget::class,
            ImageWidget::class,
            ButtonWidget::class,
            GalleryWidget::class,
            RepeaterWidget::class,
            TaxonomyTermsWidget::class,
            HtmlWidget::class,
        ]);
    }
}
