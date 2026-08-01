<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\DynamicPost;

class Ticket extends Model
{
    protected $table = 'tickets';

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
        'id' => 'integer',
        'raised_by' => 'integer',
        'user_id' => 'integer',
        'status_id' => 'integer',
        'priority_id' => 'integer',
        'ticket_type_id' => 'integer',
        'ticket_department_id' => 'integer',
        'property_id' => 'integer',
        'due_date' => 'date:Y-m-d',
    ];

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'raised_by',
            'id'
        );
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'id'
        );
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(
            TicketStatus::class,
            'status_id',
            'id'
        );
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(
            TicketPriority::class,
            'priority_id',
            'id'
        );
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(
            TicketType::class,
            'ticket_type_id',
            'id'
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            TicketDepartment::class,
            'ticket_department_id',
            'id'
        );
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'property_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(
            TicketAttachment::class,
            'ticket_id',
            'id'
        );
    }

    public function ccUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'ticket_cc_users',
            'ticket_id',
            'user_id'
        );

        // Do not use withTimestamps() unless ticket_cc_users
        // has created_at and updated_at columns.
    }

    public function responses(): HasMany
    {
        return $this->hasMany(
            TicketResponse::class,
            'ticket_id',
            'id'
        );
    }
}
