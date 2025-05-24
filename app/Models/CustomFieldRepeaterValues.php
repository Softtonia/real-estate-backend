<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeaterValues extends Model
{
    use HasFactory;
    protected $guarded=[];
    protected $table='custom_field_repeater_values';
    

    // Define the relationship with the CustomField model (assuming you have one)
    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    // Define the relationship with the CustomFieldOption model (assuming you have one)
    public function customFieldRepeaterOption()
    {
        return $this->belongsTo(CustomFieldRepeaterOption::class, 'custom_field_repeater_options_id');
    }

    // A custom field value belongs to a developer listing
    public function developerListing()
    {
        return $this->belongsTo(DeveloperListing::class, 'developer_listing_id');
    }


}
