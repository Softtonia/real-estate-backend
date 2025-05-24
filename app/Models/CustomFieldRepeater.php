<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeater extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'custom_field_repeaters';

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function repeaterOptions()
    {
        return $this->hasMany(CustomFieldRepeaterOption::class, 'custom_field_repeater_id');
    }
    
        public function repeaterFieldsOptions()
    {
        return $this->hasMany(CustomFieldRepeaterOption::class, 'custom_field_repeater_id');
    }

    
}
