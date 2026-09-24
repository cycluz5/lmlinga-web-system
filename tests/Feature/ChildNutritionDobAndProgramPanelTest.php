<?php

namespace Tests\Feature;

use App\Models\ChildNutrition;
use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildNutritionService;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * DOB date rules + Nutrition Program panel (Iron / Vitamin A) for Child Nutrition.
 */
class ChildNutritionDobAndProgramPanelTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const DOB = '2025-12-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMb027(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-027',
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-027',
            'first_name' => 'Infant',
            'last_name' => 'Dob',
            'relation' => 'Son',
            'birthday' => self::DOB,
            'sex' => 'Male',
        ], $overrides));

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function routeParams(Household $household, Resident $resident): array
    {
        return [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
    }

    public function test_date_before_dob_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $this->from(route('household-profiling.members.child-nutrition', $params))
            ->post(route('household-profiling.members.child-nutrition.store', $params), [
                'iron' => ['1st' => '2025-12-19', '2nd' => '', '3rd' => ''],
            ])
            ->assertSessionHasErrors('iron.1st');

        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_date_on_dob_is_accepted(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'iron' => ['1st' => self::DOB, '2nd' => '', '3rd' => ''],
        ])->assertRedirect();

        $record = ChildNutrition::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(self::DOB, $record->iron_1st_date?->format('Y-m-d'));
    }

    public function test_date_after_dob_is_accepted(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'iron' => ['1st' => '2025-12-21', '2nd' => '', '3rd' => ''],
        ])->assertRedirect();

        $record = ChildNutrition::query()->where('resident_id', $resident->id)->first();
        $this->assertSame('2025-12-21', $record->iron_1st_date?->format('Y-m-d'));
    }

    public function test_html_date_inputs_include_dob_min_and_breastfeeding_max_today(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);
        $today = now()->toDateString();

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        foreach ([
            'newborn[breastfeeding_date]',
            'iron[1st]',
            'iron[2nd]',
            'iron[3rd]',
            'vitamin_a[va-6-11]',
            'vitamin_a[va-12-59-1]',
            'vitamin_a[va-12-59-2]',
            'mnp[mnp-6-11]',
            'mnp[mnp-12-23]',
            'lns_sq[lns-6-11]',
            'lns_sq[lns-12-23]',
            'mam[identified][date]',
            'sam[identified][date]',
        ] as $name) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($name, '/').'"[^>]*\bmin="'.preg_quote(self::DOB, '/').'"/s',
                $html,
                "Expected min=".self::DOB." on {$name}"
            );
        }

        $this->assertMatchesRegularExpression(
            '/name="newborn\[breastfeeding_date\]"[^>]*\bmax="'.preg_quote($today, '/').'"/s',
            $html
        );
    }

    public function test_nullable_blank_dates_remain_valid(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'iron' => ['1st' => '', '2nd' => '', '3rd' => ''],
            'vitamin_a' => [
                'va-6-11' => '',
                'va-12-59-1' => '',
                'va-12-59-2' => '',
            ],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_offline_sync_rejects_date_before_dob(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMb027([
            'member_no' => 'MB-028',
        ]);

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            [
                '_health_action' => 'child_nutrition_store',
                'iron' => ['1st' => '2025-12-19', '2nd' => '', '3rd' => ''],
            ],
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));

        $response->assertStatus(422);
        $this->assertSame(0, ChildNutrition::query()->where('resident_id', $resident->id)->count());
    }

    public function test_nutrition_program_panel_shows_no_record_without_iron_or_vitamin_a(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-child-nut-program-iron[^>]*>\s*No record\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-child-nut-program-va-6-11[^>]*>\s*No record\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-child-nut-program-va-12-59[^>]*>\s*No record\s*</u',
            $html
        );
    }

    public function test_nutrition_program_panel_shows_latest_iron_and_vitamin_a_dates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'iron' => [
                '1st' => '2025-12-21',
                '2nd' => '2026-01-10',
                '3rd' => '2025-12-28',
            ],
            'vitamin_a' => [
                'va-6-11' => '2026-02-01',
                'va-12-59-1' => '2026-03-01',
                'va-12-59-2' => '2026-04-15',
            ],
        ])->assertRedirect();

        $beforeCount = ChildNutrition::query()->where('resident_id', $resident->id)->count();

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-child-nut-program-iron[^>]*>\s*01\/10\/2026\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-child-nut-program-va-6-11[^>]*>\s*02\/01\/2026\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-child-nut-program-va-12-59[^>]*>\s*04\/15\/2026\s*</u',
            $html
        );

        $this->assertSame(
            $beforeCount,
            ChildNutrition::query()->where('resident_id', $resident->id)->count()
        );
    }

    public function test_overall_status_and_latest_assessment_remain_no_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMb027();
        $params = $this->routeParams($household, $resident);

        $this->post(route('household-profiling.members.child-nutrition.store', $params), [
            'newborn' => [
                'length' => '50.00',
                'weight' => '3.20',
                'breastfeeding_date' => self::DOB,
            ],
            'iron' => ['1st' => '2025-12-21', '2nd' => '', '3rd' => ''],
            'vitamin_a' => [
                'va-6-11' => '2026-01-05',
                'va-12-59-1' => '',
                'va-12-59-2' => '',
            ],
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-nutrition', $params))
            ->assertOk()
            ->getContent();

        preg_match('/id="lml-child-nut-status-panel"[\s\S]*?<\/aside>/u', $html, $panelMatch);
        $panel = $panelMatch[0] ?? '';
        $this->assertNotSame('', $panel);

        $this->assertMatchesRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNo record\b/u',
            $panel
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNormal\b/u',
            $panel
        );
        $this->assertMatchesRegularExpression(
            '/Latest Assessment[\s\S]*?<dt>Date<\/dt>\s*<dd>No record<\/dd>/u',
            $panel
        );
        $this->assertMatchesRegularExpression(
            '/<dt>Weight<\/dt>\s*<dd>No record<\/dd>/u',
            $panel
        );
        $this->assertMatchesRegularExpression(
            '/<dt>Height<\/dt>\s*<dd>No record<\/dd>/u',
            $panel
        );
        $this->assertMatchesRegularExpression(
            '/<dt>MUAC<\/dt>\s*<dd>No record<\/dd>/u',
            $panel
        );
    }

    public function test_latest_meaningful_date_helper_picks_latest(): void
    {
        $this->assertSame(
            '2026-04-15',
            ChildNutritionService::latestMeaningfulDate([
                'a' => '2026-03-01',
                'b' => '',
                'c' => '2026-04-15',
                'd' => '2025-12-21',
            ])
        );
        $this->assertNull(ChildNutritionService::latestMeaningfulDate(['a' => '', 'b' => null]));
    }
}
