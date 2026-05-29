<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Property;
use App\Models\Location;
use App\Models\Amenity;
use App\Models\Purpose;
use App\Models\PropertyType;
use App\Models\Status;
use App\Observers\PropertyObserver;
use App\Observers\LocationObserver;
use App\Observers\AmenityObserver;
use App\Observers\PurposeObserver;
use App\Observers\PropertyTypeObserver;
use App\Observers\StatusObserver;
use App\Models\AmenitiesCategory;
use App\Observers\AmenitiesCategoryObserver;
use App\Models\MailConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Property::observe(PropertyObserver::class);
        Amenity::observe(AmenityObserver::class);
        Purpose::observe(PurposeObserver::class);
        PropertyType::observe(PropertyTypeObserver::class);
        Status::observe(StatusObserver::class);
        AmenitiesCategory::observe(AmenitiesCategoryObserver::class);
        require_once app_path('Helpers/domain_helper.php');
        if (Schema::hasTable('mail_configs')) {
            $mailConfig = MailConfig::first();

            if ($mailConfig) {
                Config::set('mail.mailer', $mailConfig->mailer);
                Config::set('mail.host', $mailConfig->host);
                Config::set('mail.port', $mailConfig->port);
                Config::set('mail.username', $mailConfig->username);
                Config::set('mail.password', $mailConfig->password);
                Config::set('mail.encryption', $mailConfig->encryption);
                Config::set('mail.from.address', $mailConfig->from_address);
                Config::set('mail.from.name', $mailConfig->from_name);
            }
        }
        DB::listen(function ($query) {
            if ($query->time > 500) {
                Log::channel('daily')->warning('Slow Query Detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                ]);
            }
        });
    }
}
