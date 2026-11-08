<?php

namespace App\Support;

/**
 * Presentation-only CSS/modifier mapping for Household Amenities UI.
 * Does not derive business statuses — maps already-resolved machine values to view classes.
 */
final class HouseholdAmenitiesPresentation
{
    public static function basicSafeWaterModifier(string $status): string
    {
        return match ($status) {
            DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH => 'is-with',
            DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT => 'is-without',
            default => 'is-pending',
        };
    }

    public static function managementStatusModifier(string $status): string
    {
        return match ($status) {
            DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED => 'is-safely-managed',
            DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED => 'is-not-safely-managed',
            default => 'is-pending',
        };
    }

    public static function toiletStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY => 'Sanitary',
            DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY => 'Unsanitary',
            default => 'Not Yet Determined',
        };
    }

    public static function toiletStatusModifier(string $status): string
    {
        return match (strtolower(trim($status))) {
            DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY => 'is-sanitary',
            DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY => 'is-unsanitary',
            default => 'is-pending',
        };
    }

    public static function toiletStatusIcon(string $status): string
    {
        return strtolower(trim($status)) === DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY
            ? 'bi-shield-x'
            : 'bi-shield-check';
    }

    public static function validationStatusModifier(string $status): string
    {
        return match ($status) {
            'completed' => 'is-completed',
            'partially_recorded' => 'is-partial',
            default => 'is-pending',
        };
    }

    public static function solidWasteStatusModifier(string $status): string
    {
        return $status === 'good_practice' ? 'is-good' : 'is-pending';
    }
}
