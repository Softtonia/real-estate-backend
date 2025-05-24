<?php

namespace App\Observers;

use App\Models\AmenitiesCategory;
use Illuminate\Support\Str;
class AmenitiesCategoryObserver
{
    /**
     * Handle the AmenitiesCategory "created" event.
     */
    public function created(AmenitiesCategory $amenitiesCategory): void
    {
        //
    }
    public function creating(AmenitiesCategory $amenitiesCategory)
    {
        $amenitiesCategory->slug = Str::slug($amenitiesCategory->name);
    }

    /**
     * Handle the AmenitiesCategory "updated" event.
     */
    public function updated(AmenitiesCategory $amenitiesCategory): void
    {
        //
    }
    
    public function updating(AmenitiesCategory $amenitiesCategory)
    {
        $amenitiesCategory->slug = Str::slug($amenitiesCategory->name);
    }

    /**
     * Handle the AmenitiesCategory "deleted" event.
     */
    public function deleted(AmenitiesCategory $amenitiesCategory): void
    {
        //
    }

    /**
     * Handle the AmenitiesCategory "restored" event.
     */
    public function restored(AmenitiesCategory $amenitiesCategory): void
    {
        //
    }

    /**
     * Handle the AmenitiesCategory "force deleted" event.
     */
    public function forceDeleted(AmenitiesCategory $amenitiesCategory): void
    {
        //
    }
}
