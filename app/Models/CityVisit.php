<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CityVisit extends Model
{
    use HasFactory;

    protected $fillable = ['city_id', 'user_id', 'count'];

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
