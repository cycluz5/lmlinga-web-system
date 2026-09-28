<?php

namespace Tests\Feature;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Deworming SE Status is retrieved from the member's household
 * (households.household_type), not entered on the deworming form.
 */
class DewormingHouseholdSeStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-28')->startOfDay());

        // Live schema stores SE status on the household.
        if (! Schema::hasColumn('households', 'household_type')) {
            Schema::table('households', function ($table): void {
                $table->string('household_type', 16)->nullable();
            });
        }

        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedChild(string $householdNo, string $memberNo, ?string $householdType): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);
        $household->forceFill(['household_type' => $householdType])->save();

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Dewey',
            'last_name' => 'Worm',
            'birthday' => '2023-02-01',
            'sex' => 'Male',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    private function params(Household $household, Resident $resident): array
    {
        return ['householdNo' => $household->household_no, 'memberId' => $resident->member_no];
    }

    public function test_form_shows_household_se_status_read_only_without_a_select(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-301', 'MB-301', 'NHTS');

        foreach ([
            route('household-profiling.members.child-nutrition', $this->params($household, $resident)),
            route('household-profiling.members.deworming.create', $this->params($household, $resident)),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/value="NHTS"\s+readonly/', $html);
            $this->assertStringNotContainsString('name="se_status"', $html);
        }
    }

    public function test_saving_without_se_status_uses_the_household_value(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-302', 'MB-302', 'NHTS');

        $this->post(route('household-profiling.members.deworming.store', $this->params($household, $resident)), [
            'year' => 2026,
            'round' => '1',
            'date_given' => '2026-07-15',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record = DewormingRecord::query()->where('resident_id', $resident->id)->firstOrFail();
        if (Schema::hasColumn('deworming_records', 'se_status')) {
            $this->assertSame('NHTS', $record->se_status);
        }

        $html = $this->get(route('household-profiling.members.child-nutrition', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<td>\s*NHTS\s*<\/td>/', $html);
    }

    public function test_household_value_wins_over_a_submitted_se_status(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-303', 'MB-303', 'Non-NHTS');

        $this->post(route('household-profiling.members.deworming.store', $this->params($household, $resident)), [
            'year' => 2026,
            'round' => '1',
            'se_status' => 'NHTS',
            'date_given' => '2026-07-15',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $html = $this->get(route('household-profiling.members.child-nutrition', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<td>\s*Non-NHTS\s*<\/td>/', $html);
    }

    public function test_changing_the_household_type_updates_what_is_displayed(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-304', 'MB-304', 'Non-NHTS');

        $this->post(route('household-profiling.members.deworming.store', $this->params($household, $resident)), [
            'year' => 2026,
            'round' => '1',
            'date_given' => '2026-07-15',
        ])->assertRedirect();

        $household->forceFill(['household_type' => 'NHTS'])->save();

        $html = $this->get(route('household-profiling.members.child-nutrition', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<td>\s*NHTS\s*<\/td>/', $html);
    }

    public function test_household_without_type_shows_not_set_and_still_saves(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild('HH-305', 'MB-305', null);

        $html = $this->get(route('household-profiling.members.child-nutrition', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/value="Not set"\s+readonly/', $html);

        $this->post(route('household-profiling.members.deworming.store', $this->params($household, $resident)), [
            'year' => 2026,
            'round' => '1',
            'date_given' => '2026-07-15',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DewormingRecord::query()->where('resident_id', $resident->id)->count());
    }
}
