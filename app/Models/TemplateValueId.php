<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TemplateValueId extends Model
{
    use HasFactory;

    protected $table = 'template_value_id';

    protected $fillable = [
        'name',
        'slug',
        'post_type',
        'status'
    ];

    public $timestamps = true;


}
