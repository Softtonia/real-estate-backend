<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketDepartment extends Model
{
    protected $fillable = [
        'icon_id',
        'ticket_department_name',
        'display_order',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'icon_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'ticket_department_id');
    }
}
