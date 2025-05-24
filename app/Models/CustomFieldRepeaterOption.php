<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldRepeaterOption extends Model
{
    use HasFactory;
    protected $guarded=[];
    protected $table='custom_field_repeater_options';


    public function repeaterField()
    {
        return $this->belongsTo(CustomFieldRepeater::class, 'repeater_id');
    }

}
