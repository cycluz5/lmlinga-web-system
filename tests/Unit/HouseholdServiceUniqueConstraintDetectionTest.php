<?php

namespace Tests\Unit;

use App\Services\HouseholdService;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class HouseholdServiceUniqueConstraintDetectionTest extends TestCase
{
    private function detect(QueryException $exception): bool
    {
        $method = new ReflectionMethod(HouseholdService::class, 'isUniqueConstraintViolation');
        $method->setAccessible(true);

        return (bool) $method->invoke(app(HouseholdService::class), $exception);
    }

    private function queryException(string $message, string $sqlState = '23000', ?int $driverCode = null): QueryException
    {
        $previous = new PDOException($message, 0);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        $exception = new QueryException('mysql', 'insert into `households` (`household_no`, `latitude`) values (?, ?)', ['005', null], $previous);
        $exception->errorInfo = [$sqlState, $driverCode, $message];

        return $exception;
    }

    public function test_mysql_1062_duplicate_entry_is_unique_violation(): void
    {
        $exception = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '005' for key 'uq_household_no'",
            '23000',
            1062,
        );

        $this->assertTrue($this->detect($exception));
    }

    public function test_sqlite_unique_constraint_failed_is_unique_violation(): void
    {
        $exception = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: households.household_no',
            '23000',
            19,
        );

        $this->assertTrue($this->detect($exception));
    }

    public function test_not_null_latitude_failure_is_not_unique_violation(): void
    {
        $exception = $this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'latitude' cannot be null (Connection: mysql, SQL: insert into `households` (`household_no`, `latitude`) values (005, ?))",
            '23000',
            1048,
        );

        $this->assertFalse($this->detect($exception));
    }

    public function test_generic_23000_without_duplicate_signature_is_not_unique_violation(): void
    {
        $exception = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 1048 Column \'longitude\' cannot be null',
            '23000',
            1048,
        );

        $this->assertFalse($this->detect($exception));
    }
}
