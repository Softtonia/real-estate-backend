<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecentlyViewedPost extends Model
{
    use HasFactory;

    protected $table = 'user_recently_viewed_posts';

    protected $fillable = [
        'user_id',
        'guest_session_id',
        'dynamic_post_id',
        'post_type_id',
        'view_count',
        'viewed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'dynamic_post_id' => 'integer',
        'post_type_id' => 'integer',
        'view_count' => 'integer',
        'viewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dynamicPost(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function postType(): BelongsTo
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }
}
