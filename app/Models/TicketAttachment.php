<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';

    protected $fillable = [
        'ticket_id',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'id' => 'integer',
        'ticket_id' => 'integer',
        'file_size' => 'integer',
    ];

    protected $appends = [
        'file_url',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(
            Ticket::class,
            'ticket_id',
            'id'
        );
    }

    public function getFileUrlAttribute(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        return Storage::disk('public')->url(
            $this->file_path
        );
    }
}