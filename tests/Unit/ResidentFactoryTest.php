<?php

namespace Tests\Unit;

use App\Models\Household;
use App\Models\Resident;
use Database\Factories\ResidentFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R02-C — ResidentFactory default must not randomly create Head.
 */
class ResidentFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_relation_pool_excludes_head(): void
    {
        $this->assertSame(['Spouse', 'Son', 'Daughter'], ResidentFactory::DEFAULT_RELATIONS);
        $this->assertNotContains('Head', ResidentFactory::DEFAULT_RELATIONS);

        $relations = [];
        for ($i = 0; $i < 30; $i++) {
            $relations[] = Resident::factory()->make()->relation;
        }

        foreach ($relations as $relation) {
            $this->assertNotSame('Head', $relation);
            $this->assertContains($relation, ResidentFactory::DEFAULT_RELATIONS);
        }
    }

    public function test_head_state_sets_relation_head(): void
    {
        $resident = Resident::factory()->head()->create();

        $this->assertSame('Head', $resident->relation);
        $this->assertTrue($resident->fresh()->isHouseholdHead());
    }

    public function test_two_default_residents_can_share_household_without_head_collision(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-R02C1']);

        $a = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-061',
        ]);
        $b = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-062',
        ]);

        $this->assertNotSame('Head', $a->relation);
        $this->assertNotSame('Head', $b->relation);
        $this->assertCount(2, $household->fresh()->residents);
        $this->assertFalse($a->fresh()->isHouseholdHead());
        $this->assertFalse($b->fresh()->isHouseholdHead());
    }

    public function test_one_head_and_multiple_default_residents_can_coexist(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-R02C2']);

        $head = Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-070',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-071',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-072',
        ]);

        $this->assertSame('Head', $head->relation);
        $this->assertSame(3, $household->fresh()->residents()->count());
        $this->assertSame(
            1,
            Resident::query()
                ->where('household_id', $household->id)
                ->where('relation', 'Head')
                ->count()
        );
    }

    public function test_two_explicit_heads_on_same_household_still_violate_unique_constraint(): void
    {
        if (! $this->residentsHaveUniqueActiveHeadConstraint()) {
            $this->markTestSkipped(
                'Laravel-migration sqlite does not unique-index household heads. Application validation remains the production guard. Live ERD unique-head is not added by this test.'
            );
        }

        $household = Household::factory()->create(['household_no' => 'HH-R02C3']);

        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-080',
        ]);

        $this->expectException(QueryException::class);

        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-081',
        ]);
    }

    public function test_explicit_relation_head_override_still_works(): void
    {
        $resident = Resident::factory()->create([
            'relation' => 'Head',
            'member_no' => 'MB-090',
        ]);

        $this->assertSame('Head', $resident->relation);
    }

    private function residentsHaveUniqueActiveHeadConstraint(): bool
    {
        try {
            foreach (Schema::getIndexes('residents') as $index) {
                if (! ($index['unique'] ?? false)) {
                    continue;
                }
                $name = strtolower((string) ($index['name'] ?? ''));
                if (str_contains($name, 'head')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
