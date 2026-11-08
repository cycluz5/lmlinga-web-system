<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildBirthHistoryService;
use App\Support\ChildNutritionErdMode;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ErdChildNutritionSchema;
use Tests\TestCase;

/**
 * SR-01 autofill, scope B (decision B1): Child Nutrition "New Born" shows the Birth History values because both
 * pages use the same stored fields (ERD: child_nutrition.*_at_birth_* / initiated_breastfeeding_date; legacy:
 * child_nutritions.newborn_*). No separate prefill exists or is needed; this guards that behaviour.
 */
class ChildNutritionNewbornFromBirthHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function seedChild(string $householdNo, string $memberNo, string $firstName = 'Baby'): array
    {
        $household = Household::factory()->create(['household_no' => $householdNo, 'zone' => 'Zone 1', 'street' => 'Birth St.']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => $firstName,
            'last_name' => 'Newborn',
            'relation' => 'Son',
            'birthday' => now()->subDays(10)->format('Y-m-d'),
            'sex' => 'Male',
        ]);

        return ['householdNo' => $householdNo, 'memberId' => $memberNo];
    }

    private function saveBirthHistory(array $params, string $weight, string $length, string $bfDate): void
    {
        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), [
            'birth_weight' => $weight,
            'birth_length' => $length,
            'pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
            'breastfeeding_date' => $bfDate,
        ])->assertRedirect();
    }

    /** Value attribute of the named input on the Child Nutrition page. */
    private function newbornValue(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*name="newborn\['.$key.'\]"[^>]*>/s', $html, $m), "newborn[$key] input present");
        $this->assertSame(1, preg_match('/value="([^"]*)"/', $m[0], $v), "newborn[$key] has a value attribute");

        return html_entity_decode($v[1], ENT_QUOTES);
    }

    private function nutritionPage(array $params): string
    {
        return $this->get(route('household-profiling.members.child-nutrition', $params))->assertOk()->getContent();
    }

    private function assertNewbornShows(string $html, float $weight, float $length, string $bfDate): void
    {
        $this->assertEqualsWithDelta($weight, (float) $this->newbornValue($html, 'weight'), 0.001, 'Weight at Birth');
        $this->assertEqualsWithDelta($length, (float) $this->newbornValue($html, 'length'), 0.001, 'Length at Birth');
        $this->assertSame($bfDate, $this->newbornValue($html, 'breastfeeding_date'));
    }

    public function test_legacy_schema_new_born_fields_show_saved_birth_history(): void
    {
        $params = $this->seedChild('HH-861', 'MB-861');

        $this->saveBirthHistory($params, '3.10', '49.00', '2024-06-01');

        $this->assertNewbornShows($this->nutritionPage($params), 3.10, 49.00, '2024-06-01');
    }

    public function test_production_erd_schema_new_born_fields_show_saved_birth_history(): void
    {
        ErdChildNutritionSchema::ensure();
        Schema::dropIfExists('child_birth_histories');
        ChildNutritionErdMode::resetCachedState();
        $this->assertTrue(ChildNutritionErdMode::isActive());

        $params = $this->seedChild('121', 'MB-862');

        $this->saveBirthHistory($params, '2.85', '47.50', '2024-07-15');

        $this->assertNewbornShows($this->nutritionPage($params), 2.85, 47.50, '2024-07-15');
    }

    public function test_updated_birth_history_is_reflected_without_a_second_copy(): void
    {
        $params = $this->seedChild('HH-863', 'MB-863');

        $this->saveBirthHistory($params, '3.10', '49.00', '2024-06-01');
        $this->saveBirthHistory($params, '3.40', '50.50', '2024-06-02');

        $this->assertNewbornShows($this->nutritionPage($params), 3.40, 50.50, '2024-06-02');
    }

    public function test_child_without_birth_history_gets_empty_fields_and_never_another_childs_values(): void
    {
        $withHistory = $this->seedChild('HH-864', 'MB-864', 'Recorded');
        $withoutHistory = $this->seedChild('HH-865', 'MB-865', 'Unrecorded');

        $this->saveBirthHistory($withHistory, '3.25', '48.75', '2024-05-20');

        $html = $this->nutritionPage($withoutHistory);
        $this->assertSame('', $this->newbornValue($html, 'weight'));
        $this->assertSame('', $this->newbornValue($html, 'length'));
        $this->assertSame('', $this->newbornValue($html, 'breastfeeding_date'));
        $this->assertStringNotContainsString('48.75', $html);
        $this->assertStringNotContainsString('2024-05-20', $html);
    }
}
