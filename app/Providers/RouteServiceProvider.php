<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Default API limiter
        |--------------------------------------------------------------------------
        */
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'api'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        /*
        |--------------------------------------------------------------------------
        | Membership limiters
        |--------------------------------------------------------------------------
        */
        RateLimiter::for('membership-public', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'membership-public'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('membership-user', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'membership-user'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('membership-payment', function (Request $request) {
            return Limit::perMinute(300)
                ->by($this->throttleKey($request, 'membership-payment'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('membership-feature-usage', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'membership-feature-usage'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('membership-admin', function (Request $request) {
            return Limit::perMinute(1200)
                ->by($this->throttleKey($request, 'membership-admin'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        /*
        |--------------------------------------------------------------------------
        | Notification limiters
        |--------------------------------------------------------------------------
        */
        RateLimiter::for('notification-admin', function (Request $request) {
            return Limit::perMinute(1200)
                ->by($this->throttleKey($request, 'notification-admin'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('notification-user', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'notification-user'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('notification-device', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'notification-device'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        /*
        |--------------------------------------------------------------------------
        | KYC limiters
        |--------------------------------------------------------------------------
        */
        RateLimiter::for('kyc-admin', function (Request $request) {
            return Limit::perMinute(1200)
                ->by($this->throttleKey($request, 'kyc-admin'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('kyc-user', function (Request $request) {
            return Limit::perMinute(600)
                ->by($this->throttleKey($request, 'kyc-user'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        RateLimiter::for('kyc-upload', function (Request $request) {
            return Limit::perMinute(300)
                ->by($this->throttleKey($request, 'kyc-upload'))
                ->response(fn () => $this->tooManyAttemptsResponse());
        });

        /*
        |--------------------------------------------------------------------------
        | Routes
        |--------------------------------------------------------------------------
        */
        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    private function throttleKey(Request $request, string $prefix): string
    {
        $userId = optional($request->user())->id;

        if ($userId) {
            return $prefix . ':user:' . $userId;
        }

        $bearerToken = $request->bearerToken();

        if ($bearerToken) {
            return $prefix . ':token:' . sha1($bearerToken);
        }

        $applicationPassword = $request->header('X-Application-Password');

        if ($applicationPassword) {
            return $prefix . ':app-password:' . sha1($applicationPassword);
        }

        $apiClientId = $request->header('X-Api-Client-Id');

        if ($apiClientId) {
            return $prefix . ':api-client:' . sha1($apiClientId);
        }

        return $prefix . ':ip:' . $request->ip();
    }

    private function tooManyAttemptsResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Too many requests. Please wait a few seconds and try again.',
            'error' => 'Too Many Attempts',
        ], 429);
    }
}