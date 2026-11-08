<?php

namespace Tests\Unit;

use App\Support\MaternalCareEligibility;
use PHPUnit\Framework\TestCase;

class MaternalCareEligibilityTest extends TestCase
{
    public function test_canonical_female_is_allowed(): void
    {
        $this->assertTrue(MaternalCareEligibility::allows('Female'));
        $this->assertTrue(MaternalCareEligibility::allows('female'));
        $this->assertTrue(MaternalCareEligibility::allows('F'));
    }

    public function test_male_and_unknown_are_rejected(): void
    {
        $this->assertFalse(MaternalCareEligibility::allows('Male'));
        $this->assertFalse(MaternalCareEligibility::allows('male'));
        $this->assertFalse(MaternalCareEligibility::allows('Unknown'));
        $this->assertFalse(MaternalCareEligibility::allows(''));
        $this->assertFalse(MaternalCareEligibility::allows(null));
        $this->assertFalse(MaternalCareEligibility::allows(' '));
    }
}
