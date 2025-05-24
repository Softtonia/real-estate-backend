<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customfieldvalue extends Model
{
    use HasFactory;
    protected $guarded=[];
    protected $table='custom_field_value';
    
    
    // Define the relationship with the Propertylist model
    public function propertyListing()
    {
        return $this->belongsTo(Propertylist::class, 'properties_listing_id');
    }

    // Define the relationship with the CustomField model (assuming you have one)
    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    // Define the relationship with the CustomFieldOption model (assuming you have one)
    public function customFieldOption()
    {
        return $this->belongsTo(CustomFieldOption::class, 'custom_field_options_id');
    }

    // A custom field value belongs to a developer listing
    public function developerListing()
    {
        return $this->belongsTo(DeveloperListing::class, 'developer_listing_id');
    }


}
