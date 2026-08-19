<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'dynamic_post_id',
        'post_type_id',
        'user_ids',
        'lead_type_id',
        'created_by_admin',
    ];

    protected $casts = [
        'user_ids' => 'array',
        'dynamic_post_id' => 'integer',
        'post_type_id' => 'integer',
        'lead_type_id' => 'integer',
        'created_by_admin' => 'integer',
    ];

    /**
     * The dynamic post associated with the lead.
     */
    public function dynamicPost(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    /**
     * The post type of the dynamic post.
     */
    public function postType(): BelongsTo
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    /**
     * The lead type.
     */
    public function leadType(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'lead_type_id');
    }

    /**
     * Admin user who created this lead if created by admin.
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin');
    }

    /**
     * Accessor for user data associated with user_ids array.
     */
    public function getUsersDataAttribute()
    {
        return User::whereIn('id', $this->user_ids ?? [])
            ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
            ->get();
    }
}
