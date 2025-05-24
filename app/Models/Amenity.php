<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    use HasFactory;
    protected $table='amenities';
    protected $guarded=[];

    public function category()
    {
        return $this->belongsTo(AmenitiesCategory::class, 'amenities_categories_id');
    }
    
    public function media()
    {
        return $this->belongsTo(Media::class, 'media_id');
    }


}
