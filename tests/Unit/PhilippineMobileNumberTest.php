<?php

namespace Tests\Unit;

use App\Rules\PhilippineMobileNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Refinement item 1: dummy or malformed mobile numbers are rejected for health-worker accounts. */
class PhilippineMobileNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['lmlinga.staff_mobile_dummy_check' => true]);
    }

    private function error(string $number, ?string $unchanged = null): ?string
    {
        $v = Validator::make(['m' => $number], ['m' => [new PhilippineMobileNumber($unchanged)]]);

        return $v->fails() ? $v->errors()->first('m') : null;
    }

    /** @return array<string, array{string}> */
    public static function realLookingNumbers(): array
    {
        return [
            'local' => ['09171112222'],
            'local 2' => ['09562718493'],
            'plus 63' => ['+639562718493'],
            '63 prefix' => ['639562718493'],
            'spaces and dashes' => ['0956 271-8493'],
        ];
    }

    #[DataProvider('realLookingNumbers')]
    public function test_real_looking_numbers_pass(string $number): void
    {
        $this->assertNull($this->error($number));
    }

    /** @return array<string, array{string}> */
    public static function malformedNumbers(): array
    {
        return [
            'too short' => ['0917123'],
            'too long' => ['091712345678'],
            'letters' => ['09abc123456'],
            'landline' => ['0282345678'],
            'wrong start' => ['08171234567'],
        ];
    }

    #[DataProvider('malformedNumbers')]
    public function test_malformed_numbers_fail_the_format_check(string $number): void
    {
        $this->assertSame(PhilippineMobileNumber::FORMAT_MESSAGE, $this->error($number));
    }

    /** @return array<string, array{string}> */
    public static function dummyNumbers(): array
    {
        return [
            'all zeros' => ['09000000000'],
            'repeated digits' => ['09171111111'],
            'counting up' => ['09123456789'],
            'counting down' => ['09987654321'],
            'unassigned prefix' => ['09012718493'],
        ];
    }

    #[DataProvider('dummyNumbers')]
    public function test_placeholder_numbers_are_rejected(string $number): void
    {
        $this->assertSame(PhilippineMobileNumber::DUMMY_MESSAGE, $this->error($number));
    }

    public function test_an_unchanged_stored_number_is_not_rechecked(): void
    {
        $this->assertNull($this->error('09123456789', '+639123456789'));
        $this->assertSame(PhilippineMobileNumber::DUMMY_MESSAGE, $this->error('09123456780', '09123456789'));
    }
}
