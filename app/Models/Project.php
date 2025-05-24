<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $casts = [
        'amenities' => 'array',
    ];
    
    protected $guarded=[];
    public function propertyType()
{
    return $this->belongsTo(PropertyType::class, 'property_type_id');
}
    public function location()
{
    return $this->belongsTo(Location::class, 'location_id');
}
    public function amenity()
{
    return $this->belongsTo(Amenity::class, 'amenities');
}
    public function propertiesname()
{
    return $this->belongsTo(Property::class, 'properties_id');
}
    public function status()
{
    return $this->belongsTo(Status::class, 'property_status');
}
    public function builder()
{
    return $this->belongsTo(Builder::class, 'developers');
}

   public function consultancyProjects()
{
    return $this->hasMany(CompanyConsultancyProject::class, 'project_id');
}


}
