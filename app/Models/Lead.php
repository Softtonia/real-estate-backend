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
        'user_ids','lead_type_id'
    ];

    public function property()
    {
        return $this->belongsTo(PropertyList::class, 'property_id');
    }

    public function project()
    {
        return $this->belongsTo(ProjectList::class, 'project_id');
    }

    public function developer()
    {
        return $this->belongsTo(Developerlist::class, 'developer_id');
    }

    public function leadType(){
        return $this->belongsTo(LeadType::class, 'lead_type_id');
    }




     protected $casts = [
        'user_ids' => 'array', // "[\"1\"]" → [1]
    ];

    // Accessor for users
    public function getUsersDataAttribute()
    {
        return User::whereIn('id', $this->user_ids ?? [])->select('id', 'first_name','last_name', 'email','phone','area_locality')->get();
    }
}
