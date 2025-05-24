<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldOption extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'custom_field_options';

    public function customField()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}
