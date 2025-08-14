<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'property_id',
        'project_id',
        'developer_id',
        'user_id'
    ];

    public function property()
    {
        return $this->belongsTo(PropertyListing::class, 'property_id');
    }

    public function project()
    {
        return $this->belongsTo(ProjectListing::class, 'project_id');
    }

    public function developer()
    {
        return $this->belongsTo(DeveloperListing::class, 'developer_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
