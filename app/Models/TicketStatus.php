<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketStatus extends Model
{
    protected $table = 'ticket_status';

    protected $fillable = [
        'icon_id',
        'ticket_status_name',
        'display_order',
    ];

    protected $casts = [
        'id' => 'integer',
        'icon_id' => 'integer',
        'display_order' => 'integer',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(
            Media::class,
            'icon_id',
            'id'
        );
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(
            Ticket::class,
            'status_id',
            'id'
        );
    }
}