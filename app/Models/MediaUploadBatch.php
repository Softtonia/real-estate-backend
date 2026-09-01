<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaUploadBatch extends Model
{
    protected $table = 'media_upload_batches';

    protected $fillable = [
        'batch_uuid',
        'user_id',
        'post_type_id',
        'custom_field_id',
        'field_slug',
        'expected_count',
        'uploaded_count',
        'processed_count',
        'failed_count',
        'status',
        'metadata',
    ];

    protected $casts = [
        'expected_count' => 'integer',
        'uploaded_count' => 'integer',
        'processed_count' => 'integer',
        'failed_count' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function postType(): BelongsTo
    {
        return $this->belongsTo(PostType::class);
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaBatchItem::class, 'batch_id')->orderBy('sort_order', 'asc');
    }

    public function calculateProgressPercentage(): float
    {
        if ($this->expected_count <= 0) {
            return 100.0;
        }

        $totalDone = $this->processed_count + $this->failed_count;
        $pct = ($totalDone / $this->expected_count) * 100;

        return round(min(100, max(0, $pct)), 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' || ($this->expected_count > 0 && ($this->processed_count + $this->failed_count) >= $this->expected_count);
    }
}
