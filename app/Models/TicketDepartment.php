<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketDepartment extends Model
{
    protected $table = 'ticket_departments';

    protected $fillable = [
        'icon_id',
        'ticket_department_name',
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
            'ticket_department_id',
            'id'
        );
    }
}