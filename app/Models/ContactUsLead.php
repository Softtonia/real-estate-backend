<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactUsLead extends Model
{
    use HasFactory;

     protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'country_code',
        'phone_number',
        'message','status'
    ];

     protected $appends = ['full_phone'];

    public function getFullPhoneAttribute(): string
    {
        return trim(($this->country_code ?? '') . ' ' . ($this->phone_number ?? ''));
    }

}
