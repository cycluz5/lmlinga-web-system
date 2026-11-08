<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Refinement 2.0-1 — staff-entered 3-digit household_no on create.
 */
class HouseholdNumberInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function validHouseholdPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Layuan St., Brgy. La Medalla',
            'latitude' => '7.12345678',
            'longitude' => '125.12345678',
            'accomplished_by' => 'Maria BHW',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function validMemberPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Spouse',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => '123456789012',
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ], $overrides);
    }

    public function test_create_persists_exact_three_digit_household_no(): void
    {
        $create = $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'household_no' => '121',
        ]));
        $create->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $create->headers->get('Location')
        );

        $household = Household::query()->firstOrFail();
        $this->assertSame('121', $household->household_no);
        $this->assertSame('121', $household->getAttributes()['household_no']);
    }

    public function test_create_persists_leading_zeroes_exactly(): void
    {
        $create = $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'household_no' => '001',
        ]));
        $create->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $create->headers->get('Location')
        );

        $household = Household::query()->firstOrFail();
        $this->assertSame('001', $household->household_no);
        $this->assertNotSame('1', $household->household_no);
        $this->assertNotSame('HH-001', $household->household_no);
    }

    public function test_exact_duplicate_household_no_is_rejected(): void
    {
        Household::factory()->create(['household_no' => '121']);

        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $this->validHouseholdPayload([
                'household_no' => '121',
            ]))
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->assertSame(1, Household::query()->count());
    }

    public function test_semantic_duplicate_of_existing_hh_prefixed_number_is_rejected(): void
    {
        Household::factory()->create(['household_no' => 'HH-001']);

        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $this->validHouseholdPayload([
                'household_no' => '001',
            ]))
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->assertSame(1, Household::query()->count());
        $this->assertSame('HH-001', Household::query()->firstOrFail()->household_no);
    }

    #[DataProvider('invalidHouseholdNumbers')]
    public function test_invalid_household_no_is_rejected(string $invalid): void
    {
        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $this->validHouseholdPayload([
                'household_no' => $invalid,
            ]))
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->assertSame(0, Household::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidHouseholdNumbers(): array
    {
        return [
            'too_short' => ['12'],
            'too_long' => ['1210'],
            'alphanumeric' => ['12A'],
            'hh_prefix' => ['HH-121'],
            'letters' => ['ABC'],
        ];
    }

    public function test_blank_household_no_is_rejected(): void
    {
        $payload = $this->validHouseholdPayload();
        unset($payload['household_no']);

        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $payload)
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->from(route('household-profiling.create'))
            ->post(route('household-profiling.store'), $this->validHouseholdPayload([
                'household_no' => '',
            ]))
            ->assertRedirect(route('household-profiling.create'))
            ->assertSessionHasErrors('household_no');

        $this->assertSame(0, Household::query()->count());
    }

    public function test_household_id_remains_database_generated_and_posted_pk_is_ignored(): void
    {
        $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'household_no' => '121',
            'id' => 99999,
            'household_id' => 99999,
        ]))->assertRedirect();

        $household = Household::query()->firstOrFail();
        $this->assertSame('121', $household->household_no);
        $this->assertNotSame(99999, (int) $household->getKey());
        $this->assertNotSame(99999, (int) $household->id);
    }

    public function test_edit_cannot_change_household_no_even_if_posted(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-810',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
        ]);
        $originalKey = $household->getKey();

        $this->put(route('household-profiling.update', ['householdNo' => 'HH-810']), $this->validHouseholdPayload([
            'household_no' => '999',
            'zone' => 'Zone 5',
            'street' => 'Cateel Bay St.',
        ]))->assertRedirect(route('household-profiling.view', ['householdNo' => 'HH-810']));

        $household->refresh();
        $this->assertSame('HH-810', $household->household_no);
        $this->assertSame($originalKey, $household->getKey());
        $this->assertSame('Zone 5', $household->zone);
    }

    public function test_create_redirect_reload_uses_three_digit_route(): void
    {
        $create = $this->post(route('household-profiling.store'), $this->validHouseholdPayload([
            'household_no' => '121',
            'accomplished_by' => 'Reload Staff',
        ]));
        $create->assertRedirect();
        $this->assertStringContainsString(
            '/environmental-health/household-water-supply',
            (string) $create->headers->get('Location')
        );

        $html = $this->get(route('household-profiling.view', ['householdNo' => '121']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-household-no="121"', $html);
        $this->assertStringContainsString('data-source="db"', $html);
        $this->assertStringContainsString('Zone 2', $html);
    }

    public function test_existing_hh_prefixed_household_remains_resolvable(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 1',
            'street' => 'Legacy St.',
            'accomplished_by' => 'Legacy Staff',
        ]);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('data-household-no="HH-001"', false)
            ->assertSee('data-source="db"', false);

        $this->get(route('household-profiling.edit', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('HH-001', false);
    }

    public function test_nested_member_routes_work_under_three_digit_household_no(): void
    {
        $household = Household::factory()->create(['household_no' => '121']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-121',
            'relation' => 'Head',
            'first_name' => 'Head',
            'last_name' => 'One',
        ]);

        $this->get(route('household-profiling.members.create', ['householdNo' => '121']))
            ->assertOk()
            ->assertSee('data-persistable="1"', false);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => '121']),
            $this->validMemberPayload([
                'relation' => 'Son',
                'first_name' => 'Nested',
                'last_name' => 'Member',
                'sex' => 'Male',
            ])
        )->assertRedirect();

        $this->assertSame(2, $household->residents()->count());
        $child = $household->residents()->where('first_name', 'Nested')->first();
        $this->assertNotNull($child);
        $this->assertSame((int) $household->getKey(), (int) $child->household_id);
    }

    public function test_spot_mapping_index_still_shows_existing_hh_prefixed_household(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 1',
            'street' => 'Marker St.',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
        ]);
        Resident::factory()->create([
            'household_id' => Household::query()->where('household_no', 'HH-001')->firstOrFail()->id,
            'relation' => 'Head',
            'first_name' => 'Mapped',
            'last_name' => 'Head',
        ]);

        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('HH-001', false)
            ->assertSee('Mapped Head', false);
    }

    public function test_profiling_list_hh_no_column_strips_leading_prefix_and_keeps_route_identity(): void
    {
        Household::factory()->create(['household_no' => 'HH-001']);
        Household::factory()->create(['household_no' => 'HH-002']);
        Household::factory()->create(['household_no' => 'HH-003']);
        Household::factory()->create(['household_no' => '121']);
        Household::factory()->create(['household_no' => '999']);

        $html = $this->get(route('household-profiling.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-001"[\s\S]*?lml-hh-profiling__hh-no">001</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-002"[\s\S]*?lml-hh-profiling__hh-no">002</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-household-no="HH-003"[\s\S]*?lml-hh-profiling__hh-no">003</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-household-no="121"[\s\S]*?lml-hh-profiling__hh-no">121</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-household-no="999"[\s\S]*?lml-hh-profiling__hh-no">999</',
            $html
        );
        $this->assertStringNotContainsString('lml-hh-profiling__hh-no">HH-001<', $html);
        $this->assertStringNotContainsString('lml-hh-profiling__hh-no">HH-002<', $html);
        $this->assertStringNotContainsString('lml-hh-profiling__hh-no">HH-003<', $html);
        $this->assertStringContainsString(
            route('household-profiling.view', ['householdNo' => 'HH-001'], false),
            $html
        );
        $this->assertStringContainsString(
            route('household-profiling.members.create', ['householdNo' => 'HH-001'], false),
            $html
        );
        $this->assertStringContainsString(
            route('household-profiling.view', ['householdNo' => '121'], false),
            $html
        );

        $this->assertSame('HH-001', Household::query()->where('household_no', 'HH-001')->value('household_no'));
        $this->assertSame('121', Household::query()->where('household_no', '121')->value('household_no'));
    }
}
