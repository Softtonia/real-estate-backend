<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MediaFile extends Model
{
    protected $fillable = [
        'disk',
        'context',
        'post_type_slug',
        'field_slug',
        'directory',
        'path',
        'file_name',
        'original_name',
        'mime_type',
        'extension',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'uploaded_by' => 'integer',
    ];

    protected $appends = [
        'url',
        'size_kb',
    ];

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        return Storage::disk($this->disk ?? 'public')->url($this->path);
    }

    public function getSizeKbAttribute(): ?float
    {
        if (!$this->size) {
            return null;
        }

        return round($this->size / 1024, 2);
    }
}