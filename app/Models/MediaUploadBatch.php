<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaUploadBatch extends Model
{
    use HasFactory;

    protected $table = 'media_upload_batches';

    protected $fillable = [
        'batch_uuid',
        'user_id',
        'dynamic_post_id',
        'post_type_slug',
        'custom_field_id',
        'field_slug',
        'context',
        'expected_count',
        'uploaded_count',
        'processed_count',
        'failed_count',
        'status',
        'progress_percent',
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'expected_count' => 'integer',
        'uploaded_count' => 'integer',
        'processed_count' => 'integer',
        'failed_count' => 'integer',
        'progress_percent' => 'float',
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dynamicPost(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaBatchItem::class, 'batch_id')->orderBy('sort_order')->orderBy('id');
    }
}
