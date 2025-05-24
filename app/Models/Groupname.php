<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Groupname extends Model
{
    use HasFactory;
    protected $table = 'group_name'; // Ensure the table name matches your database table name
    protected $fillable = [
        'group_name',
    ];
    
    // public function customfield()
    // {
    //     return $this->belongsTo(CustomField::class, 'group_id', 'id');
    // }

    public function customFields()
{
    return $this->hasMany(CustomField::class, 'group_id', 'id');
}
}
