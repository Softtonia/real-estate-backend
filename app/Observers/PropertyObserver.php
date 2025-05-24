<?php

namespace App\Observers;

use App\Models\Property;

use Illuminate\Support\Str;
class PropertyObserver
{
    /**
     * Handle the Property "created" event.
     */
    public function created(Property $property): void
    {
        //
    }

    /**
     * Handle the Property "updated" event.
     */
    public function updated(Property $property): void
    {
        //
    }

    /**
     * Handle the Property "deleted" event.
     */
    public function deleted(Property $property): void
    {
        //
    }

    /**
     * Handle the Property "restored" event.
     */
    public function restored(Property $property): void
    {
        //
    }

    /**
     * Handle the Property "force deleted" event.
     */
    public function forceDeleted(Property $property): void
    {
        //
    }
    public function creating(Property $property)
    {
        // Automatically generate and set the slug if it's not provided
        if (!$property->slug) {
            $property->slug = Str::slug($property->name);
        }
    }
    public function updating(Property $property)
    {
        // Regenerate the slug if the name is being updated
        if ($property->isDirty('name')) {
            $property->slug = Str::slug($property->name);
        }
    }
}
