<?php

namespace Tests\Unit;

use App\Support\DemoHouseholdWaterSupply;
use App\Support\HouseholdAmenitiesPresentation;
use PHPUnit\Framework\TestCase;

class HouseholdAmenitiesPresentationTest extends TestCase
{
    public function test_basic_safe_water_modifiers_match_view_classes(): void
    {
        $this->assertSame('is-with', HouseholdAmenitiesPresentation::basicSafeWaterModifier(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH));
        $this->assertSame('is-without', HouseholdAmenitiesPresentation::basicSafeWaterModifier(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT));
        $this->assertSame('is-pending', HouseholdAmenitiesPresentation::basicSafeWaterModifier(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_PENDING));
    }

    public function test_management_and_validation_modifiers(): void
    {
        $this->assertSame('is-safely-managed', HouseholdAmenitiesPresentation::managementStatusModifier(DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED));
        $this->assertSame('is-not-safely-managed', HouseholdAmenitiesPresentation::managementStatusModifier(DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED));
        $this->assertSame('is-pending', HouseholdAmenitiesPresentation::managementStatusModifier(DemoHouseholdWaterSupply::MANAGEMENT_STATUS_PENDING));

        $this->assertSame('is-completed', HouseholdAmenitiesPresentation::validationStatusModifier('completed'));
        $this->assertSame('is-partial', HouseholdAmenitiesPresentation::validationStatusModifier('partially_recorded'));
        $this->assertSame('is-pending', HouseholdAmenitiesPresentation::validationStatusModifier('not_conducted'));
    }

    public function test_toilet_and_solid_waste_presentation(): void
    {
        $this->assertSame('Sanitary', HouseholdAmenitiesPresentation::toiletStatusLabel(DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY));
        $this->assertSame('is-unsanitary', HouseholdAmenitiesPresentation::toiletStatusModifier(DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY));
        $this->assertSame('bi-shield-x', HouseholdAmenitiesPresentation::toiletStatusIcon(DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY));
        $this->assertSame('bi-shield-check', HouseholdAmenitiesPresentation::toiletStatusIcon(DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY));
        $this->assertSame('is-good', HouseholdAmenitiesPresentation::solidWasteStatusModifier('good_practice'));
        $this->assertSame('is-pending', HouseholdAmenitiesPresentation::solidWasteStatusModifier('not_yet_determined'));
    }
}
