<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyType extends Model
{
    use HasFactory;
    protected $table='property_types';
    protected $guarded=[];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
    public function propertyLists()
{
    return $this->belongsToMany(Propertylist::class);
}


    public function properties()
{
    return $this->hasMany(Propertylist::class);
}


    public function propertiesListings()
    {
        return $this->hasMany(Propertylist::class, 'property_type_id');
    }

}
