<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    use HasFactory;
    protected $table = 'user_permissions';

    protected $fillable = [
        'role_id', 'model_name', 'create', 'edit', 'read', 'delete'
    ];
}
