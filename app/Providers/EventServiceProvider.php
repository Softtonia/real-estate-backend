<?php

namespace App\Providers;

use App\Events\ApiClientAccessDenied;
use App\Events\ApiClientAccessGranted;
use App\Listeners\LogApiClientAccessDenied;
use App\Listeners\LogApiClientAccessGranted;
use App\Listeners\UpdateApiClientLastUsed;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        \App\Events\ApplicationPasswordCreated::class => [
            \App\Listeners\WriteApplicationPasswordAuditLog::class,
            \App\Listeners\ClearClientCache::class,
        ],

        \App\Events\ApplicationPasswordRevoked::class => [
            \App\Listeners\WriteApplicationPasswordAuditLog::class,
            \App\Listeners\ClearClientCache::class,
        ],
        ApiClientAccessGranted::class => [
            LogApiClientAccessGranted::class,
            UpdateApiClientLastUsed::class,
        ],

        ApiClientAccessDenied::class => [
            LogApiClientAccessDenied::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
