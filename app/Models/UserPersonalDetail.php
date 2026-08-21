<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPersonalDetail extends Model
{
    use HasFactory;

    protected $table = 'user_personal_details';

    protected $fillable = [
        'user_id',
        'alternate_number',
        'profile_photo',
        'about_us',
        'country_id',
        'state_id',
        'city_id',
        'area_locality',
        'colony',
        'street_address',
        'address',
        'pin_code',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
