<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaBatchItem extends Model
{
    protected $table = 'media_batch_items';

    protected $fillable = [
        'batch_id',
        'media_file_id',
        'client_file_id',
        'original_name',
        'file_size',
        'mime_type',
        'is_featured',
        'sort_order',
        'status',
        'error_message',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'media_file_id' => 'integer',
        'file_size' => 'integer',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
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
