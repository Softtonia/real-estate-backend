<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_number',
        'raised_by',
        'user_id',
        'subject',
        'message',
        'status_id',
        'priority_id',
        'media_attachment',
        'ticket_type_id',
        'ticket_department_id',
        'due_date',
        'property_id',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
    ];

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'ticket_department_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function ccUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'ticket_cc_users',
            'ticket_id',
            'user_id'
        )->withTimestamps();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TicketResponse::class);
    }
}
