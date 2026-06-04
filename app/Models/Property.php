<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;
    protected $table='properties';
    protected $fillable=['name','slug','display_properties_order','property_image','updated_at'];


    public function propertytype()
    {
        return $this->hasMany(PropertyType::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($category) {
            // Delete all related subcategories
            $category->propertytype()->delete();
        });
    }

}
