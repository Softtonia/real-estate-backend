<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectAnalytic extends Model
{
    use HasFactory;
    protected $guarded=[];
    protected $table='project_analytics';

     public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Projectlist::class);
    }


}
