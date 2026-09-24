<?php

namespace Tests\Unit;

use App\Support\HouseholdNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HouseholdNumberDisplayTest extends TestCase
{
    #[DataProvider('displayCases')]
    public function test_for_display_strips_leading_hh_prefix_only(string $stored, string $expected): void
    {
        $this->assertSame($expected, HouseholdNumber::forDisplay($stored));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function displayCases(): array
    {
        return [
            'legacy hyphenated' => ['HH-001', '001'],
            'hh-002' => ['HH-002', '002'],
            'hh-003' => ['HH-003', '003'],
            'lowercase prefix' => ['hh-001', '001'],
            'already numeric padded' => ['001', '001'],
            'three digit 121' => ['121', '121'],
            'three digit 999' => ['999', '999'],
            'does not strip embedded hh' => ['xHH-001', 'xHH-001'],
        ];
    }

    public function test_for_display_does_not_cast_to_int(): void
    {
        $this->assertSame('001', HouseholdNumber::forDisplay('HH-001'));
        $this->assertNotSame('1', HouseholdNumber::forDisplay('HH-001'));
        $this->assertNotSame(1, HouseholdNumber::forDisplay('001'));
    }
}
