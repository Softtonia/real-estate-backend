<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'value_type',
        'is_public',
        'description',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function formattedValue(): mixed
    {
        return match ($this->value_type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($this->value) ? (float) $this->value : 0,
            'json' => json_decode($this->value ?: '[]', true),
            default => $this->value,
        };
    }
}