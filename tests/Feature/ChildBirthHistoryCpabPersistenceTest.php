<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildBirthHistoryService;
use App\Support\ChildImmunizationErdMode;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Birth History CPAB persists on the child immunization header (cpab column),
 * like the live 3NF schema where child_birth_histories does not exist.
 */
class ChildBirthHistoryCpabPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Live schema: no legacy birth history table, so CPAB can only go to the header.
        Schema::dropIfExists('child_birth_histories');
        ChildImmunizationErdMode::resetCachedState();

        $this->actingAsStaff(StaffRole::BHW);
    }

    private function seedChild(string $householdNo = 'HH-870', string $memberNo = 'MB-870'): array
    {
        $household = Household::factory()->create(['household_no' => $householdNo, 'zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Cora',
            'last_name' => 'Pab',
            'relation' => 'Daughter',
            'birthday' => now()->subMonths(5)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    private function params(Household $household, Resident $resident): array
    {
        return ['householdNo' => $household->household_no, 'memberId' => $resident->member_no];
    }

    private function save(Household $household, Resident $resident, array $data)
    {
        return $this->post(
            route('household-profiling.members.child-immunization.birth-history.store', $this->params($household, $resident)),
            array_merge(['birth_weight' => '3.10', 'birth_length' => '49.00', 'breastfeeding_date' => now()->subMonths(5)->format('Y-m-d')], $data)
        );
    }

    private function headerCpab(Resident $resident): ?string
    {
        return DB::table('child_immunizations')->where('resident_id', $resident->id)->value('cpab');
    }

    public function test_migration_adds_cpab_to_the_header_table(): void
    {
        $this->assertTrue(Schema::hasColumn('child_immunizations', 'cpab'));
    }

    public function test_cpab_is_saved_and_shown_again(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->save($household, $resident, ['pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES, $this->headerCpab($resident));

        $edit = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<option\s+value="at_least_2_doses_1_month_prior"\s+selected/', $edit);

        $view = $this->get(route('household-profiling.members.child-immunization', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('At least 2 doses received at least 1 month prior to delivery', $view);
    }

    public function test_cpab_can_be_changed_and_cleared_on_the_same_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->save($household, $resident, ['pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES])->assertRedirect();
        $this->save($household, $resident, ['pcab' => ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5])->assertRedirect();
        $this->assertSame(ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5, $this->headerCpab($resident));

        $this->save($household, $resident, ['pcab' => ''])->assertRedirect();
        $this->assertNull($this->headerCpab($resident));
        $this->assertSame(1, DB::table('child_immunizations')->where('resident_id', $resident->id)->count());
    }

    public function test_no_cpab_does_not_create_an_empty_header_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->save($household, $resident, ['pcab' => ''])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('child_immunizations')->where('resident_id', $resident->id)->count());
    }

    public function test_cpab_is_added_to_an_existing_immunization_record_without_touching_it(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        DB::table('child_immunizations')->insert([
            'resident_id' => $resident->id,
            'remarks' => 'existing vaccines',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->save($household, $resident, ['pcab' => ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5])->assertRedirect();

        $row = DB::table('child_immunizations')->where('resident_id', $resident->id)->first();
        $this->assertSame(ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5, $row->cpab);
        $this->assertSame('existing vaccines', $row->remarks);
        $this->assertSame(1, DB::table('child_immunizations')->where('resident_id', $resident->id)->count());
    }

    public function test_another_residents_cpab_is_not_changed(): void
    {
        ['household' => $householdA, 'resident' => $a] = $this->seedChild('HH-871', 'MB-871');
        ['household' => $householdB, 'resident' => $b] = $this->seedChild('HH-872', 'MB-872');

        $this->save($householdA, $a, ['pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES])->assertRedirect();
        $this->save($householdB, $b, ['pcab' => ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5])->assertRedirect();

        $this->assertSame(ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES, $this->headerCpab($a));
        $this->assertSame(ChildBirthHistoryService::PCAB_TT3_TD3_TO_TT5_TD5, $this->headerCpab($b));
    }

    public function test_invalid_cpab_value_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();

        $this->save($household, $resident, ['pcab' => 'made_up'])->assertSessionHasErrors('pcab');
        $this->assertSame(0, DB::table('child_immunizations')->where('resident_id', $resident->id)->count());
    }
}
