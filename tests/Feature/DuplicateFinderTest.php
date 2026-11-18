<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\DuplicateFinder;
use App\Support\NonResidentFamilyPlanningService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

class DuplicateFinderTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private const BIRTHDAY = '1990-05-17';

    private function household(string $zone = 'Zone 1', array $extra = []): Household
    {
        return Household::factory()->create(array_merge(['zone' => $zone], $extra));
    }

    private function member(Household $hh, string $first, string $last, ?string $birthday = self::BIRTHDAY, array $extra = []): Resident
    {
        return Resident::factory()->create(array_merge([
            'household_id' => $hh->getKey(),
            'first_name' => $first,
            'last_name' => $last,
            'middle_name' => null,
            'birthday' => $birthday,
        ], $extra));
    }

    private function finder(): DuplicateFinder
    {
        return app(DuplicateFinder::class);
    }

    public function test_same_household_match_returns_contract_item(): void
    {
        $hh = $this->household('Zone 1', ['household_no' => 'HH-5001']);
        $this->member($hh, 'Juan', 'Delacruz', self::BIRTHDAY, ['middle_name' => 'Santos']);

        $items = $this->finder()->forMember($hh, 'Juan', 'Delacruz', self::BIRTHDAY);

        $this->assertCount(1, $items);
        $this->assertSame(['name', 'relation', 'household_no', 'url'], array_keys($items[0]));
        $this->assertSame('Delacruz, Juan S.', $items[0]['name']);
        $this->assertSame('HH-5001', $items[0]['household_no']);
        $this->assertIsString($items[0]['relation']);
        $this->assertIsString($items[0]['url']);
        $this->assertStringContainsString('/members/', $items[0]['url']);
    }

    public function test_matching_ignores_case_spacing_accents_and_middle_name(): void
    {
        $hh = $this->household();
        $this->member($hh, 'José  Maria', 'De La Cruz', self::BIRTHDAY, ['middle_name' => 'Reyes']);

        $items = $this->finder()->forMember($hh, '  jose maria ', 'DE la  CRUZ', self::BIRTHDAY);

        $this->assertCount(1, $items);
        $this->assertSame('jose maria', DuplicateFinder::normalizeName('  José   MARIA '));
    }

    public function test_different_birthday_does_not_match(): void
    {
        $hh = $this->household();
        $this->member($hh, 'Juan', 'Delacruz');

        $this->assertSame([], $this->finder()->forMember($hh, 'Juan', 'Delacruz', '1991-05-17'));
    }

    public function test_different_household_does_not_match_for_member(): void
    {
        $a = $this->household();
        $b = $this->household();
        $this->member($a, 'Juan', 'Delacruz');

        $this->assertSame([], $this->finder()->forMember($b, 'Juan', 'Delacruz', self::BIRTHDAY));
    }

    public function test_empty_or_null_birthday_never_matches(): void
    {
        $hh = $this->household();
        $this->member($hh, 'Juan', 'Delacruz');

        $this->assertSame([], $this->finder()->forMember($hh, 'Juan', 'Delacruz', null));
        $this->assertSame([], $this->finder()->forMember($hh, 'Juan', 'Delacruz', ''));
        $this->assertSame([], $this->finder()->forMember($hh, 'Juan', 'Delacruz', '  '));
    }

    public function test_excludes_the_given_resident(): void
    {
        $hh = $this->household();
        $self = $this->member($hh, 'Juan', 'Delacruz');

        $this->assertSame([], $this->finder()->forMember($hh, 'Juan', 'Delacruz', self::BIRTHDAY, (int) $self->getKey()));

        $this->member($hh, 'Juan', 'Delacruz');
        $this->assertCount(1, $this->finder()->forMember($hh, 'Juan', 'Delacruz', self::BIRTHDAY, (int) $self->getKey()));
    }

    public function test_soft_deleted_residents_are_ignored(): void
    {
        $hh = $this->household();
        $this->member($hh, 'Juan', 'Delacruz')->delete();

        $this->assertSame([], $this->finder()->forMember($hh, 'Juan', 'Delacruz', self::BIRTHDAY));
    }

    public function test_admin_head_check_finds_match_in_any_household(): void
    {
        $this->member($this->household('Zone 3', ['household_no' => 'HH-5003']), 'Juan', 'Delacruz');
        $admin = $this->actingAsStaff(StaffRole::ADMIN);

        $items = $this->finder()->forHead($admin, 'Juan', 'Delacruz', self::BIRTHDAY);

        $this->assertCount(1, $items);
        $this->assertSame('HH-5003', $items[0]['household_no']);
    }

    public function test_bhw_head_check_is_limited_to_assigned_zones(): void
    {
        $this->member($this->household('Zone 3', ['household_no' => 'HH-5003']), 'Juan', 'Delacruz');
        $worker = $this->actingAsStaff(StaffRole::BHW); // helper assigns Zone 1

        $this->assertSame([], $this->finder()->forHead($worker, 'Juan', 'Delacruz', self::BIRTHDAY));

        $this->member($this->household('Zone 1', ['household_no' => 'HH-5001']), 'Juan', 'Delacruz');
        $items = $this->finder()->forHead($worker, 'Juan', 'Delacruz', self::BIRTHDAY);
        $this->assertCount(1, $items);
        $this->assertSame('HH-5001', $items[0]['household_no']);
    }

    public function test_worker_without_zone_gets_nothing(): void
    {
        $this->member($this->household('Zone 1'), 'Juan', 'Delacruz');
        $worker = $this->actingAsStaff(StaffRole::BHW);
        $worker->currentAppointment->forceFill(['assigned_zone' => null])->save();
        if (method_exists($worker->currentAppointment, 'syncAssignedZones')) {
            $worker->currentAppointment->syncAssignedZones([]);
        }

        $this->assertSame([], $this->finder()->forHead($worker->fresh(['currentAppointment']), 'Juan', 'Delacruz', self::BIRTHDAY));
    }

    public function test_head_check_requires_birthday(): void
    {
        $this->member($this->household(), 'Juan', 'Delacruz');
        $admin = $this->actingAsStaff(StaffRole::ADMIN);

        $this->assertSame([], $this->finder()->forHead($admin, 'Juan', 'Delacruz', null));
    }

    public function test_sentinel_household_is_excluded(): void
    {
        $this->member($this->household('Zone 1', ['household_no' => NonResidentFamilyPlanningService::SENTINEL_HOUSEHOLD_NO]), 'Juan', 'Delacruz');
        $admin = $this->actingAsStaff(StaffRole::ADMIN);

        $this->assertSame([], $this->finder()->forHead($admin, 'Juan', 'Delacruz', self::BIRTHDAY));
    }

    public function test_results_are_capped_at_three(): void
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN);
        for ($i = 0; $i < 5; $i++) {
            $this->member($this->household(), 'Juan', 'Delacruz');
        }

        $this->assertCount(3, $this->finder()->forHead($admin, 'Juan', 'Delacruz', self::BIRTHDAY));
    }

    public function test_extra_first_name_token_does_not_match(): void
    {
        $hh = $this->household();
        $this->member($hh, 'Maria', 'Delacruz');

        $this->assertSame([], $this->finder()->forMember($hh, 'Maria Teresa', 'Delacruz', self::BIRTHDAY));
    }

    public function test_hyphen_and_apostrophe_names_match_themselves_only(): void
    {
        $hh = $this->household();
        $this->member($hh, 'Sean', "O'Brien");
        $this->member($hh, 'Ana', 'De la Cruz-Santos');

        $this->assertCount(1, $this->finder()->forMember($hh, 'Sean', "O'Brien", self::BIRTHDAY));
        $this->assertCount(1, $this->finder()->forMember($hh, 'ana', 'de  la cruz-santos', self::BIRTHDAY));
        $this->assertSame([], $this->finder()->forMember($hh, 'Ana', 'De la Cruz Santos', self::BIRTHDAY));
        $this->assertSame([], $this->finder()->forMember($hh, 'Sean', 'OBrien', self::BIRTHDAY));
    }

    public function test_results_are_ordered_by_last_name_then_first_name(): void
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->member($hh, 'Juan', 'Delacruz', self::BIRTHDAY, ['middle_name' => 'Beta']);
        $this->member($hh, 'Juan', 'Delacruz', self::BIRTHDAY, ['middle_name' => 'Alpha']);

        $items = $this->finder()->forHead($admin, 'Juan', 'Delacruz', self::BIRTHDAY);
        $this->assertCount(2, $items);
        $this->assertSame(['Delacruz, Juan B.', 'Delacruz, Juan A.'], array_column($items, 'name'));
    }

    public function test_datetime_stored_birthday_still_matches(): void
    {
        $hh = $this->household();
        $this->member($hh, 'Juan', 'Delacruz', self::BIRTHDAY.' 00:00:00');

        $this->assertCount(1, $this->finder()->forMember($hh, 'Juan', 'Delacruz', self::BIRTHDAY));
    }

    public function test_head_in_soft_deleted_household_is_ignored(): void
    {
        $admin = $this->actingAsStaff(StaffRole::ADMIN);
        $hh = $this->household();
        $this->member($hh, 'Juan', 'Delacruz');
        if (! Schema::hasColumn($hh->getTable(), 'deleted_at')) {
            $this->markTestSkipped('households has no deleted_at column in this schema.');
        }
        $hh->newQuery()->whereKey($hh->getKey())->update(['deleted_at' => now()]);

        $this->assertSame([], $this->finder()->forHead($admin, 'Juan', 'Delacruz', self::BIRTHDAY));
    }
}
