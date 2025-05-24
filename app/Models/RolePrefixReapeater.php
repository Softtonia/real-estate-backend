<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePrefixReapeater extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_id',
        'role_prefix_slug',
        'role_prefix',
        'created_by'
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
