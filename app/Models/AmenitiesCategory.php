<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmenitiesCategory extends Model
{
    use HasFactory;
    protected $table='amenities_categories';
    protected $guarded=[];

    public function media()
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function mediaIcon()
    {
        return $this->belongsTo(Media::class, 'icon_id');
    }

    public function amenities()
    {
        return $this->hasMany(Amenity::class,'amenities_categories_id');
    }

}
