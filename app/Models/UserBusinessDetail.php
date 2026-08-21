<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBusinessDetail extends Model
{
    use HasFactory;

    protected $table = 'user_business_details';

    protected $fillable = [
        'user_id',
        'business_name',
        'business_phone',
        'business_email',
        'business_address',
        'country_id',
        'state_id',
        'city_id',
        'area_locality',
        'colony',
        'street_address',
        'business_pin_code',
        'company_logo',
        'license_number',
        'rera_number',
        'no_of_employees',
        'about_business',
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

    /*
    |--------------------------------------------------------------------------
    | Backward Compatibility Accessors & Mutators for legacy spelling
    |--------------------------------------------------------------------------
    */

    public function getBussinessNameAttribute(): ?string
    {
        return $this->attributes['business_name'] ?? null;
    }

    public function setBussinessNameAttribute($value): void
    {
        $this->attributes['business_name'] = $value;
    }

    public function getBussinessEmailAttribute(): ?string
    {
        return $this->attributes['business_email'] ?? null;
    }

    public function setBussinessEmailAttribute($value): void
    {
        $this->attributes['business_email'] = $value;
    }

    public function getBussinessAddressAttribute(): ?string
    {
        return $this->attributes['business_address'] ?? null;
    }

    public function setBussinessAddressAttribute($value): void
    {
        $this->attributes['business_address'] = $value;
    }

    public function getBusinessLogoAttribute(): ?string
    {
        return $this->attributes['company_logo'] ?? null;
    }

    public function setBusinessLogoAttribute($value): void
    {
        $this->attributes['company_logo'] = $value;
    }
}
