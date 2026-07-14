<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketPriority extends Model
{
    protected $fillable = [
        'icon_id',
        'ticket_priority',
        'display_order',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'icon_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'priority_id');
    }
}
