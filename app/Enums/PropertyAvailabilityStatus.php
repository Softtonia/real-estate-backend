<?php

namespace App\Enums;

final class PropertyAvailabilityStatus
{
    public const AVAILABLE = 'available';
    public const RESERVED = 'reserved';
    public const SOLD = 'sold';
    public const RENTED = 'rented';
    public const OFF_MARKET = 'off_market';

    public const VALUES = [
        self::AVAILABLE,
        self::RESERVED,
        self::SOLD,
        self::RENTED,
        self::OFF_MARKET,
    ];

    public const REACTIVATION_REVIEW_STATUSES = [
        self::SOLD,
        self::RENTED,
        self::OFF_MARKET,
    ];

    public static function label(?string $status): string
    {
        return match ($status) {
            self::AVAILABLE => 'Available',
            self::RESERVED => 'Reserved',
            self::SOLD => 'Sold',
            self::RENTED => 'Rented',
            self::OFF_MARKET => 'Off Market',
            default => 'Available',
        };
    }
}