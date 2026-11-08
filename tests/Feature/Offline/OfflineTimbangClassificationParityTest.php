<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\Offline\OfflineOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * Phase 1 regression: an offline Timbang sync must persist the same server-computed
 * nutritional classifications as an equivalent online save.
 */
class OfflineTimbangClassificationParityTest extends TestCase
{
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const CLASSIFICATION_FIELDS = [
        'muac_status',
        'weight_for_age',
        'height_for_age',
        'weight_for_height',
        'bmi_value',
        'bmi_status',
        'overall_nutritional_status',
    ];

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(string $householdNo, string $memberNo, string $birthday, string $sex): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Parity',
            'last_name' => $memberNo,
            'relation' => 'Son',
            'birthday' => $birthday,
            'sex' => $sex,
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @param  array<string, mixed>  $measurement
     */
    private function saveOnline(Household $household, Resident $resident, array $measurement): void
    {
        $params = ['householdNo' => $household->household_no, 'memberId' => $resident->member_no];
        $this->post(route('household-profiling.members.nutritional-status.store', $params), $measurement)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('household-profiling.members.nutritional-status', $params));
    }

    /**
     * @param  array<string, mixed>  $measurement
     */
    private function saveOffline(Household $household, Resident $resident, array $measurement)
    {
        return $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::HEALTH_SERVICE_WRITE,
            array_merge(['_health_action' => 'timbang_record_store'], $measurement),
            ['parent_server' => [
                'household_id' => $household->getKey(),
                'household_no' => $household->household_no,
                'resident_id' => $resident->getKey(),
                'member_no' => $resident->member_no,
            ]],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedClassifications(Resident $resident): array
    {
        $record = TimbangRecord::query()->where('resident_id', $resident->getKey())->sole();

        $values = [];
        foreach (self::CLASSIFICATION_FIELDS as $field) {
            $value = $record->getAttributes()[$field] ?? null;
            $values[$field] = $field === 'bmi_value' && $value !== null ? round((float) $value, 2) : $value;
        }

        return $values;
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: list<string>}>
     */
    public static function measurementCases(): iterable
    {
        yield 'toddler with MUAC (weight/height-for-age, MUAC)' => [
            '-18 months', 'Male',
            ['measurement_date' => '__TODAY__', 'weight_kg' => '9.1', 'height_cm' => '79.5', 'muac_cm' => '13.9', 'remarks' => 'parity'],
            ['weight_for_age', 'height_for_age', 'muac_status', 'overall_nutritional_status'],
        ];
        yield 'adult (BMI)' => [
            '-30 years', 'Female',
            ['measurement_date' => '__TODAY__', 'weight_kg' => '52.0', 'height_cm' => '155.0'],
            ['bmi_value', 'bmi_status', 'overall_nutritional_status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $measurement
     * @param  list<string>  $mustBeClassified
     */
    #[DataProvider('measurementCases')]
    public function test_offline_sync_persists_same_classifications_as_online_save(
        string $ageOffset,
        string $sex,
        array $measurement,
        array $mustBeClassified,
    ): void {
        $this->actingAsFieldStaff();
        $birthday = now()->modify($ageOffset)->format('Y-m-d');
        $measurement['measurement_date'] = now()->toDateString();

        ['household' => $onlineHousehold, 'resident' => $onlineResident] = $this->seedMember('HH-701', 'MB-701', $birthday, $sex);
        ['household' => $offlineHousehold, 'resident' => $offlineResident] = $this->seedMember('HH-702', 'MB-702', $birthday, $sex);

        $this->saveOnline($onlineHousehold, $onlineResident, $measurement);
        $this->saveOffline($offlineHousehold, $offlineResident, $measurement)
            ->assertOk()
            ->assertJsonPath('code', 'SYNCED');

        $online = $this->persistedClassifications($onlineResident);
        $offline = $this->persistedClassifications($offlineResident);

        foreach ($mustBeClassified as $field) {
            $this->assertNotNull($online[$field], "Online save should classify {$field}.");
        }
        $this->assertSame($online, $offline, 'Offline sync must persist the same classifications as the online save.');
    }

    public function test_offline_sync_rejects_client_supplied_classifications(): void
    {
        $this->actingAsFieldStaff();
        ['household' => $household, 'resident' => $resident] = $this->seedMember(
            'HH-703', 'MB-703', now()->subMonths(18)->format('Y-m-d'), 'Male'
        );

        $this->saveOffline($household, $resident, [
            'measurement_date' => now()->toDateString(),
            'weight_kg' => '9.1',
            'height_cm' => '79.5',
            'overall_nutritional_status' => 'Normal',
            'weight_for_age' => 'Normal',
        ])->assertStatus(422);

        $this->assertSame(0, TimbangRecord::query()->where('resident_id', $resident->getKey())->count());
    }
}
