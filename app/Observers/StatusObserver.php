<?php

namespace App\Observers;

use App\Models\Status;
use Str;
class StatusObserver
{
    /**
     * Handle the Status "created" event.
     */
    public function created(Status $status): void
    {
        //
    }

    public function creating(Status $status)
    {
        $status->slug = Str::slug($status->name);
    }

    /**
     * Handle the Status "updated" event.
     */
    public function updated(Status $status): void
    {
        //
    }
    public function updating(Status $status)
    {
        if ($status->isDirty('name')) {
            $status->slug = Str::slug($status->name);
        }
    }

    /**
     * Handle the Status "deleted" event.
     */
    public function deleted(Status $status): void
    {
        //
    }

    /**
     * Handle the Status "restored" event.
     */
    public function restored(Status $status): void
    {
        //
    }

    /**
     * Handle the Status "force deleted" event.
     */
    public function forceDeleted(Status $status): void
    {
        //
    }
}
