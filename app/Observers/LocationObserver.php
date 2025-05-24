<?php

namespace App\Observers;

use App\Models\Location;
use Str;
class LocationObserver
{
    /**
     * Handle the Location "created" event.
     */
    public function created(Location $location): void
    {
        //
    }
    public function creating(Location $location)
    {
        $location->slug = Str::slug($location->name);
    }

    /**
     * Handle the Location "updated" event.
     */
    public function updated(Location $location): void
    {
        //
    }
    public function updating(Location $location)
    {
        if ($location->isDirty('name')) {
            $location->slug = Str::slug($location->name);
        }
    }

    /**
     * Handle the Location "deleted" event.
     */
    public function deleted(Location $location): void
    {
        //
    }

    /**
     * Handle the Location "restored" event.
     */
    public function restored(Location $location): void
    {
        //
    }

    /**
     * Handle the Location "force deleted" event.
     */
    public function forceDeleted(Location $location): void
    {
        //
    }
}
