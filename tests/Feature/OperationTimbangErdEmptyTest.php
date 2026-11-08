<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsOperationTimbang;
use App\Support\OperationTimbangMonitoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationTimbangErdEmptyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monitoring_works_when_operation_timbang_measurements_is_absent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        Schema::dropIfExists('operation_timbang_measurements');

        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Erd',
            'last_name' => 'Child',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        $service = new OperationTimbangMonitoringService();

        $this->assertTrue($service->measurementTableReady());
        $this->assertTrue($service->tablesReady());
        $this->assertFalse(Schema::hasTable('operation_timbang_measurements'));

        $emptyRows = $service->monitoringRowsForYearMonth(2026, 8);
        $this->assertCount(1, $emptyRows);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $emptyRows[0]['weight']);

        TimbangRecord::factory()->create([
            'resident_id' => $resident->id,
            'measurement_date' => '2026-08-10',
            'weight_kg' => 8.5,
        ]);

        $rows = HealthRecordsOperationTimbang::monitoringRows(2026, 8);
        $this->assertSame('8.5 kg', $rows[0]['weight']);
    }

    public function test_monitoring_stays_empty_when_timbang_records_table_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15'));

        Schema::dropIfExists('timbang_records');

        $service = new OperationTimbangMonitoringService();

        $this->assertFalse($service->measurementTableReady());
        $this->assertFalse($service->tablesReady());
        $this->assertSame([], $service->monitoringRowsForYearMonth(2026, 8));
        $this->assertSame(
            $service->emptySummaryCards(),
            $service->summaryCardsForYearMonth(2026, 8)
        );
    }
}
