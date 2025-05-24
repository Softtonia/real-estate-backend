<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purpose extends Model
{
    use HasFactory;
    protected $table='purposes';
    protected $guarded=[];
    public function propertylist()
    {
        return $this->belongsTo(Propertylist::class);
    }
}
