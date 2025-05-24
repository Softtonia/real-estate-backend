<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyAnalytic extends Model
{
    use HasFactory;
    protected $guarded=[];
    protected $table='property_analytics';

     public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function property()
    {
        return $this->belongsTo(Propertylist::class);
    }

}
