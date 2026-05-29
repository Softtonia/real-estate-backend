<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    use HasFactory;

     protected $fillable = ['client_name', 'client_id', 'client_secret', 'allowed_domain','app_type','status','nextjs_internal_key','used_by_origin','last_used_at'];

     protected $casts = [
    'allowed_domain' => 'array',

    ];


    /**
     * Scope for active clients only.
     */
    public function scopeActive($query)
    {
        return $query->where('status', '1');
    }


    public function domains(){
        return $this->hasMany(ApiClientDomain::class);
    }
}
