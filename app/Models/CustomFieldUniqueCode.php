<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldUniqueCode extends Model
{
    use HasFactory;
    protected $table='custom_field_unique_codes';
    protected $guarded=[];


     public $timestamps = true;


    public function customFields(){
        return $this->hasMany(CustomField::class, 'template_id', 'id');
    }


}
