<?php

namespace App\Observers;

use App\Models\Purpose;
use Str;
class PurposeObserver
{
    /**
     * Handle the Purpose "created" event.
     */
    public function created(Purpose $purpose): void
    {
        //
    }
    public function creating(Purpose $purpose)
    {
        $purpose->slug = Str::slug($purpose->name);
    }

    /**
     * Handle the Purpose "updated" event.
     */
    public function updated(Purpose $purpose): void
    {
        //
    }
    public function updating(Purpose $purpose)
    {
        if ($purpose->isDirty('name')) {
            $purpose->slug = Str::slug($purpose->name);
        }
    }

    /**
     * Handle the Purpose "deleted" event.
     */
    public function deleted(Purpose $purpose): void
    {
        //
    }

    /**
     * Handle the Purpose "restored" event.
     */
    public function restored(Purpose $purpose): void
    {
        //
    }

    /**
     * Handle the Purpose "force deleted" event.
     */
    public function forceDeleted(Purpose $purpose): void
    {
        //
    }
}
