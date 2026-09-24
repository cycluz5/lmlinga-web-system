# DB-16 Operation Timbang — Implementation Paths

Laravel application root: `lmlingaFinal/`  
Git root: `C:\Users\Kathlyn Cris\Desktop\LMLinga_Dev`  
Branch: `review/db16-operation-timbang-final-refresh`  
HEAD: `ff041f7835d3e1f75e50af47f110a9633d07d440`

## Core production paths

| Relative path | Notes |
|---------------|-------|
| `routes/web.php` | Named route `health-records.child-care.operation-timbang` (GET) |
| `app/Http/Controllers/HealthRecords/ChildCareSummaryController.php` | `operationTimbang(Request)` |
| `app/Support/OperationTimbangMonitoringService.php` | Year/month resolve, monitoring rows, summaries, future protection |
| `app/Support/HealthRecordsOperationTimbang.php` | Display helpers (rows, zones, filters, month sessions, years) |
| `app/Models/OperationTimbangMeasurement.php` | Persisted measurement model |
| `app/Models/Resident.php` | `operationTimbangMeasurements()` hasMany |
| `database/migrations/2026_08_26_100000_create_operation_timbang_measurements_table.php` | Schema |
| `database/factories/OperationTimbangMeasurementFactory.php` | Factory |
| `resources/views/pages/health-records/child-care-operation-timbang.blade.php` | Monitoring view |
| `resources/js/pages/health-records-operation-timbang.js` | Client filtering / navigation |

## Tests (frozen evidence; not modified by packaging)

| Relative path | Notes |
|---------------|-------|
| `tests/Feature/HealthRecordsOperationTimbangTest.php` | Page/UI/month-year/regression |
| `tests/Feature/OperationTimbangPersistenceTest.php` | Schema, persistence, historical/future period |

## Full git-tracked OT-related listing

See `IMPLEMENTATION_PATHS_GIT_TRACKED.txt` (includes historical QA evidence screenshots and scripts that remain in-repo; they are not part of the freeze decision surface but are listed for completeness).
