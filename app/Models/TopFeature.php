<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopFeature extends Model
{
    use HasFactory;

    protected $table = 'top_features';
    protected $guarded = [];


    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function property()
    {
        return $this->belongsTo(PropertyList::class, 'property_id');
    }

    public function project()
    {
        return $this->belongsTo(ProjectList::class, 'project_id');
    }
}
