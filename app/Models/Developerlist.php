<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Developerlist extends Model
{
    use HasFactory;
    protected $table='developer_listings';
    protected $guarded=[];




    public function user()
    {
        return $this->belongsTo(User::class);
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


    // A developer listing has many custom field values
    public function customFieldValues()
    {
        return $this->hasMany(Customfieldvalue::class, 'developer_listing_id');
    }

    public function country() {
        return $this->belongsTo(Country::class);
    }

    public function state() {
        return $this->belongsTo(State::class);
    }

    public function city() {
        return $this->belongsTo(City::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function analytics()
    {
        return $this->hasMany(Analytics::class, 'developer_id'); // Adjust 'developer_id' as needed
    }



}
