<?php

namespace Tests\Unit;

use App\Support\DatabaseSchemaGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

/**
 * DB12-F01 — table-absent vs database-unavailable distinction.
 */
class DatabaseSchemaGuardTest extends TestCase
{
    public function test_table_absent_returns_false(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('households')
            ->andReturn(false);

        $this->assertFalse((new DatabaseSchemaGuard)->tableExists('households'));
    }

    public function test_query_exception_becomes_service_unavailable(): void
    {
        $previous = new \PDOException('SQLSTATE[HY000] [2002] Connection refused (simulated DB12-F01)');
        $queryException = new QueryException(
            'sqlite',
            'select * from sqlite_master where type = ? and name = ?',
            ['table', 'households'],
            $previous
        );

        Schema::shouldReceive('hasTable')
            ->once()
            ->with('households')
            ->andThrow($queryException);

        try {
            (new DatabaseSchemaGuard)->tableExists('households');
            $this->fail('Expected ServiceUnavailableHttpException');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('Database is temporarily unavailable.', $e->getMessage());
            $this->assertSame($queryException, $e->getPrevious());
        }
    }

    public function test_pdo_exception_becomes_service_unavailable(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('households')
            ->andThrow(new \PDOException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->expectException(ServiceUnavailableHttpException::class);

        (new DatabaseSchemaGuard)->tableExists('households');
    }
}
