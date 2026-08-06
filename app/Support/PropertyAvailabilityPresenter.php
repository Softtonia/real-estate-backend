<?php

namespace App\Support;

use App\Enums\PropertyAvailabilityStatus;
use App\Models\DynamicPost;

final class PropertyAvailabilityPresenter
{
    public static function make(
        DynamicPost $property
    ): array {
        $status = $property->availability_status
            ?: PropertyAvailabilityStatus::AVAILABLE;

        $pendingStatus =
            $property->availability_pending_status;

        $isApproved =
            ($property->status ?? null) === 'published'
            && ($property->live_status ?? null) === 'approve';

        $isPubliclyVisible = match ($status) {
            PropertyAvailabilityStatus::AVAILABLE,
            PropertyAvailabilityStatus::RESERVED =>
                $isApproved,

            PropertyAvailabilityStatus::SOLD =>
                $isApproved
                && empty($property->availability_hidden_at)
                && !empty($property->availability_public_until)
                && $property->availability_public_until->isFuture(),

            PropertyAvailabilityStatus::RENTED,
            PropertyAvailabilityStatus::OFF_MARKET =>
                false,

            default => false,
        };

        $displayLabel = $pendingStatus
            ? PropertyAvailabilityStatus::label(
                $pendingStatus
            ) . ' (Pending Review)'
            : PropertyAvailabilityStatus::label(
                $status
            );

        return [
            'availability_status' => $status,

            'availability_label' =>
                PropertyAvailabilityStatus::label(
                    $status
                ),

            'availability_pending_status' =>
                $pendingStatus,

            'availability_review_pending' =>
                !empty($pendingStatus),

            'availability_display_label' =>
                $displayLabel,

            'availability_review_requested_at' =>
                optional(
                    $property
                        ->availability_review_requested_at
                )->toISOString(),

            'availability_public_until' =>
                optional(
                    $property->availability_public_until
                )->toISOString(),

            'availability_hidden_at' =>
                optional(
                    $property->availability_hidden_at
                )->toISOString(),

            'availability_changed_at' =>
                optional(
                    $property->availability_changed_at
                )->toISOString(),

            'availability_changed_by' =>
                $property->availability_changed_by
                    ? (int) $property
                        ->availability_changed_by
                    : null,

            'availability_notes' =>
                $property->availability_notes,

            'sold_at' =>
                optional(
                    $property->sold_at
                )->toISOString(),

            'sold_by' => $property->sold_by
                ? (int) $property->sold_by
                : null,

            'is_available' =>
                $status ===
                PropertyAvailabilityStatus::AVAILABLE,

            'is_reserved' =>
                $status ===
                PropertyAvailabilityStatus::RESERVED,

            'is_sold' =>
                $status ===
                PropertyAvailabilityStatus::SOLD,

            'is_rented' =>
                $status ===
                PropertyAvailabilityStatus::RENTED,

            'is_off_market' =>
                $status ===
                PropertyAvailabilityStatus::OFF_MARKET,

            'is_publicly_visible' =>
                $isPubliclyVisible,

            'can_receive_enquiries' =>
                $isApproved
                && $status ===
                    PropertyAvailabilityStatus::AVAILABLE
                && empty($pendingStatus),
        ];
    }
}
