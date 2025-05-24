<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyList extends Model
{
    use HasFactory;
    protected $table='properties_listing';
    protected $guarded=[];


    protected $casts = [
        'property_type_id' => 'array',
    ];
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function amenity()
    {
        return $this->belongsTo(amenities::class);
    }

        public function amenitycategory()
    {
        return $this->belongsTo(AmenitiesCategory::class);
    }

    
    public function user()
    {
        return $this->belongsTo(User::class);
    }


    public function amenities()
    {
        return $this->belongsToMany(Amenity::class);
    }

    public function gallery()
    {
        return $this->hasMany(Gallery::class);
    }


    public function purpose()
    {
        return $this->belongsTo(Purpose::class, 'purpose_id');
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }
    
    public function propertystatus()
    {
        return $this->belongsTo(Status::class, 'property_status_id');
    }
    
    public function customFieldValues()
    {
        return $this->hasMany(Customfieldvalue::class, 'properties_listing_id');
    }
    
    public function project()
    {
        return $this->belongsTo(ProjectList::class, 'project_id', 'id');
    }
    
    
    public function analytics()
    {
        return $this->hasMany(PropertyAnalytic::class, 'property_id', 'id');
    }


    public function importKeywords()
    {
        return $this->belongsToMany(ImportKeyword::class, 'keywords', 'property_id', 'keyword');
    }

    public function propertyType()
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }
    // Define relationship for created_by
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    
    public function developer()
    {
        return $this->belongsTo(Developerlist::class, 'developer_id');
    }
}
