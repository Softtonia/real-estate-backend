<?php

namespace App\Observers;

use App\Models\PropertyType;
use Str;
class PropertyTypeObserver
{
    /**
     * Handle the PropertyType "created" event.
     */
    public function created(PropertyType $propertyType): void
    {
        //
    }
    public function creating(PropertyType $propertyType)
    {
        $propertyType->slug = Str::slug($propertyType->name);
    }

    public function updating(PropertyType $propertyType)
    {
        if ($propertyType->isDirty('name')) {
            $propertyType->slug = Str::slug($propertyType->name);
        }
    }

    /**
     * Handle the PropertyType "updated" event.
     */
    public function updated(PropertyType $propertyType): void
    {
        //
    }

    /**
     * Handle the PropertyType "deleted" event.
     */
    public function deleted(PropertyType $propertyType): void
    {
        //
    }

    /**
     * Handle the PropertyType "restored" event.
     */
    public function restored(PropertyType $propertyType): void
    {
        //
    }

    /**
     * Handle the PropertyType "force deleted" event.
     */
    public function forceDeleted(PropertyType $propertyType): void
    {
        //
    }
}
