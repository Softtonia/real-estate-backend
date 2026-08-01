<?php

namespace App\Models\System;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = [
        'group',
        'key',
        'value',
        'value_type',
        'is_encrypted',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
        'status' => 'boolean',
    ];

    public const TYPE_STRING = 'string';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_JSON = 'json';
    public const TYPE_ARRAY = 'array';

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    public function formattedValue(): mixed
    {
        $value = $this->value;

        if ($this->is_encrypted && $value !== null && $value !== '') {
            try {
                $value = Crypt::decryptString($value);
            } catch (Throwable) {
                // Keep raw value if old data was stored without encryption.
            }
        }

        return match ($this->value_type) {
            self::TYPE_BOOLEAN, 'bool' => $this->toBoolean($value),
            self::TYPE_INTEGER, 'int' => $value !== null && $value !== '' ? (int) $value : null,
            self::TYPE_FLOAT, 'double', 'decimal' => $value !== null && $value !== '' ? (float) $value : null,
            self::TYPE_JSON, self::TYPE_ARRAY => $this->toArrayValue($value),
            default => $value,
        };
    }

    public function maskedValue(): ?string
    {
        $value = $this->formattedValue();

        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        return str_repeat('*', max(strlen($value) - 4, 8)) . substr($value, -4);
    }

    public function displayValue(): mixed
    {
        if ($this->is_encrypted || str_contains($this->key, 'secret')) {
            return $this->maskedValue();
        }

        return $this->formattedValue();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), [
            '1',
            'true',
            'yes',
            'active',
            'enabled',
            'on',
        ], true);
    }

    private function toArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}