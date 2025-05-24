<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Builder extends Model
{
    use HasFactory;
    protected $table = 'builders';
    protected $guarded = [];

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
    public function propertiesname()
{
    return $this->belongsTo(Property::class, 'properties_id');
}
public function propertyType()
{
    return $this->belongsTo(PropertyType::class, 'property_type_id');
}

}
