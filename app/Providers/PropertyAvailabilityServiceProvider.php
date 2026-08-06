<?php

namespace App\Providers;

use App\Console\Commands\DispatchExpiredSoldPropertyVisibilityJobs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PropertyAvailabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for(
            'property-availability-admin',
            function (Request $request) {
                return Limit::perMinute(60)->by(
                    'property-availability-admin:' .
                    $this->requestIdentity($request)
                );
            }
        );

        RateLimiter::for(
            'property-availability-owner',
            function (Request $request) {
                return Limit::perMinute(20)->by(
                    'property-availability-owner:' .
                    $this->requestIdentity($request)
                );
            }
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchExpiredSoldPropertyVisibilityJobs::class,
            ]);
        }
    }

    private function requestIdentity(
        Request $request
    ): string {
        $userId = $request->user()?->id;

        if ($userId) {
            return 'user:' . $userId;
        }

        $token = $request->bearerToken()
            ?: (string) $request->input(
                'api_token',
                ''
            );

        if ($token !== '') {
            return 'token:' . hash(
                'sha256',
                $token
            );
        }

        return 'ip:' . $request->ip();
    }
}
