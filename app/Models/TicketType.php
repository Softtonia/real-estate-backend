<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    protected $table = 'ticket_types';

    protected $fillable = [
        'icon_id',
        'ticket_type_name',
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
            'ticket_type_id',
            'id'
        );
    }
}