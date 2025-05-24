<?php

namespace App\Observers;

use App\Models\Amenity;
use Str;
class AmenityObserver
{
    /**
     * Handle the Amenity "created" event.
     */
    public function created(Amenity $amenity): void
    {
        //
    }
    public function creating(Amenity $amenity)
    {
        $amenity->slug = Str::slug($amenity->name);
    }

    /**
     * Handle the Amenity "updated" event.
     */
    public function updated(Amenity $amenity): void
    {
        //
    }
    public function updating(Amenity $amenity)
    {
        if ($amenity->isDirty('name')) {
            $amenity->slug = Str::slug($amenity->name);
        }
    }

    /**
     * Handle the Amenity "deleted" event.
     */
    public function deleted(Amenity $amenity): void
    {
        //
    }

    /**
     * Handle the Amenity "restored" event.
     */
    public function restored(Amenity $amenity): void
    {
        //
    }

    /**
     * Handle the Amenity "force deleted" event.
     */
    public function forceDeleted(Amenity $amenity): void
    {
        //
    }
}
