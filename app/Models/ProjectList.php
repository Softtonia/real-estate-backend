<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class ProjectList extends Model
{
    use HasFactory;
    protected $table='project_listings';
    protected $guarded=[];



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

    public function propertyType()
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }

    public function propertystatus()
    {
        return $this->belongsTo(Status::class, 'property_status_id');
    }

    public function customFieldValues()
    {
        return $this->hasMany(Customfieldvalue::class, 'project_listing_id');
    }

    public function customFieldRepeaterValues()
    {
        return $this->hasMany(Customfieldrepeatervalue::class, 'project_listing_id');
    }


    public function properties()
    {
        return $this->hasMany(Propertylist::class, 'project_id', 'id');
    }

    public function analytics()
    {
        return $this->hasMany(ProjectAnalytic::class, 'project_id', 'id');
    }


    public function importKeywords()
    {
        return $this->belongsToMany(ImportKeyword::class, 'keywords', 'project_id', 'keyword');
    }
       public function developer()
    {
        return $this->belongsTo(Developerlist::class, 'developer_id','id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }




}
