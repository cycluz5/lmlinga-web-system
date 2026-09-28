<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildBirthHistoryService;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdMaternalCareSchema;
use Tests\TestCase;

/**
 * Birth History pre-selects a CPAB suggestion from the mother's Td doses;
 * it is editable and never saved until staff click Save.
 */
class CpabSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_BIRTHDAY = '2026-05-10';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-28')->startOfDay());
        ErdMaternalCareSchema::ensure();
        Schema::dropIfExists('child_birth_histories');
        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function household(): Household
    {
        return Household::factory()->create(['household_no' => 'HH-880', 'zone' => 'Zone 1']);
    }

    private function member(Household $household, string $memberNo, string $first, string $sex, string $birthday): Resident
    {
        return Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => $first,
            'last_name' => 'Cruz',
            'relation' => 'Daughter',
            'sex' => $sex,
            'birthday' => $birthday,
        ]);
    }

    private function td(Resident $mother, array $dates): void
    {
        foreach (array_values($dates) as $i => $date) {
            DB::table('td_immunization')->insert([
                'resident_id' => $mother->id,
                'dose_number' => $i + 1,
                'date_given' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function editPage(Household $household, Resident $child): string
    {
        return $this->get(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $child->member_no,
        ]))->assertOk()->getContent();
    }

    public function test_two_doses_a_month_before_birth_suggests_at_least_2_doses(): void
    {
        $hh = $this->household();
        $mother = $this->member($hh, 'MB-881', 'Ana', 'Female', '1996-01-15');
        $child = $this->member($hh, 'MB-882', 'Baby', 'Male', self::CHILD_BIRTHDAY);
        $this->td($mother, ['2026-01-10', '2026-02-10']);

        $html = $this->editPage($hh, $child);

        $this->assertMatchesRegularExpression('/<option\s+value="at_least_2_doses_1_month_prior"\s+selected/', $html);
        $this->assertStringContainsString('data-cpab-suggestion="household"', $html);
        $this->assertStringContainsString('Suggested from', $html);
        $this->assertStringContainsString('Ana Cruz', $html);
        $this->assertStringContainsString('please confirm she is the mother', $html);
    }

    public function test_three_doses_before_birth_suggests_tt3_to_tt5(): void
    {
        $hh = $this->household();
        $mother = $this->member($hh, 'MB-881', 'Ana', 'Female', '1996-01-15');
        $child = $this->member($hh, 'MB-882', 'Baby', 'Male', self::CHILD_BIRTHDAY);
        $this->td($mother, ['2023-03-01', '2023-04-01', '2026-01-10']);

        $this->assertMatchesRegularExpression(
            '/<option\s+value="tt3_td3_to_tt5_td5_prior"\s+selected/',
            $this->editPage($hh, $child)
        );
    }

    public function test_second_dose_within_a_month_of_birth_gives_no_suggestion(): void
    {
        $hh = $this->household();
        $mother = $this->member($hh, 'MB-881', 'Ana', 'Female', '1996-01-15');
        $child = $this->member($hh, 'MB-882', 'Baby', 'Male', self::CHILD_BIRTHDAY);
        $this->td($mother, ['2026-03-01', '2026-04-25']);

        $html = $this->editPage($hh, $child);
        $this->assertStringNotContainsString('data-cpab-suggestion', $html);
        $this->assertDoesNotMatchRegularExpression('/<option\s+value="(at_least|tt3)[^"]*"\s+selected/', $html);
    }

    public function test_doses_after_the_birth_are_ignored(): void
    {
        $hh = $this->household();
        $mother = $this->member($hh, 'MB-881', 'Ana', 'Female', '1996-01-15');
        $child = $this->member($hh, 'MB-882', 'Baby', 'Male', self::CHILD_BIRTHDAY);
        $this->td($mother, ['2026-06-01', '2026-07-01', '2026-08-01']);

        $this->assertStringNotContainsString('data-cpab-suggestion', $this->editPage($hh, $child));
    }

    public function test_two_possible_mothers_give_no_suggestion_unless_a_delivery_matches(): void
    {
        $hh = $this->household();
        $ana = $this->member($hh, 'MB-881', 'Ana', 'Female', '1996-01-15');
        $bea = $this->member($hh, 'MB-883', 'Bea', 'Female', '1994-02-02');
        $child = $this->member($hh, 'MB-882', 'Baby', 'Male', self::CHILD_BIRTHDAY);
        $this->td($ana, ['2026-01-10', '2026-02-10']);
        $this->td($bea, ['2023-03-01', '2023-04-01', '2025-01-10']);

        $this->assertStringNotContainsString('data-cpab-suggestion', $this->editPage($hh, $child));

        // Bea's Maternal Care delivery is on the child's birthday → she is the mother.
        $careId = DB::table('maternal_care')->insertGetId([
            'resident_id' => $bea->id,
            'lmp_date' => '2025-08-01',
            'pregnancy_status' => 'Completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'maternal_care_id');
        DB::table('delivery_outcomes')->insert([
            'maternal_care_id' => $careId,
            'outcome' => 'FT',
            'date_time_of_delivery' => self::CHILD_BIRTHDAY.' 08:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->editPage($hh, $child);
        $this->assertStringContainsString('data-cpab-suggestion="delivery"', $html);
        $this->assertStringContainsString('Bea Cruz', $html);
        $this->assertMatchesRegularExpression('/<option\s+value="tt3_td3_to_tt5_td5_prior"\s+selected/', $html);
    }

    public function test_suggestion_is_not_saved_until_save_and_a_saved_answer_wins(): void
    {
        $hh = $this->household();
        $mother = $this->member($hh, 'MB-881', 'Ana', 'Female', '1996-01-15');
        $child = $this->member($hh, 'MB-882', 'Baby', 'Male', self::CHILD_BIRTHDAY);
        $this->td($mother, ['2026-01-10', '2026-02-10']);

        $this->editPage($hh, $child);
        $this->assertNull(DB::table('child_immunizations')->where('resident_id', $child->id)->value('cpab'));

        // Staff override the suggestion with the other answer and save.
        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $hh->household_no,
            'memberId' => $child->member_no,
        ]), ['pcab' => ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5])->assertRedirect();

        $html = $this->editPage($hh, $child);
        $this->assertMatchesRegularExpression('/<option\s+value="tt3_td3_to_tt5_td5_prior"\s+selected/', $html);
        $this->assertStringNotContainsString('data-cpab-suggestion', $html);
    }
}
