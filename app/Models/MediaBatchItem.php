<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaBatchItem extends Model
{
    use HasFactory;

    protected $table = 'media_batch_items';

    protected $fillable = [
        'batch_id',
        'client_file_id',
        'media_file_id',
        'file_name',
        'original_name',
        'mime_type',
        'extension',
        'size',
        'path',
        'url',
        'is_featured',
        'sort_order',
        'status',
        'error_message',
        'attempts',
        'metadata',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'attempts' => 'integer',
        'metadata' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MediaUploadBatch::class, 'batch_id');
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }
}
