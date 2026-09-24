<?php

namespace Tests\Support;

use App\Support\AtRestNarrativeField;
use Illuminate\Support\Facades\DB;

trait AssertsAtRestStoredField
{
    /**
     * @param  array<string, mixed>  $where
     */
    protected function assertAtRestStoredField(
        string $table,
        string $column,
        string $expectedPlaintext,
        array $where = [],
        ?string $aadTable = null
    ): string {
        $query = DB::table($table);
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }

        $row = $query->first();
        $this->assertNotNull($row, "Expected a {$table} row matching ".json_encode($where));

        $raw = $row->{$column} ?? null;
        $this->assertIsString($raw);
        $this->assertTrue(
            AtRestNarrativeField::isSealed($raw),
            "Expected {$table}.{$column} to be sealed ciphertext."
        );
        $this->assertStringNotContainsString($expectedPlaintext, $raw);

        $opened = AtRestNarrativeField::open($raw, $aadTable ?? $table, $column);
        $this->assertSame($expectedPlaintext, $opened);

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function assertAtRestStoredNull(string $table, string $column, array $where = []): void
    {
        $query = DB::table($table);
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }

        $row = $query->first();
        $this->assertNotNull($row);
        $this->assertNull($row->{$column} ?? null);
    }

    /**
     * Retired SEC-3 narratives (family_planning.remarks, rejection_reason) are plaintext.
     *
     * @param  array<string, mixed>  $where
     */
    protected function assertPlaintextStoredField(
        string $table,
        string $column,
        string $expectedPlaintext,
        array $where = []
    ): void {
        $query = DB::table($table);
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }

        $row = $query->first();
        $this->assertNotNull($row, "Expected a {$table} row matching ".json_encode($where));
        $this->assertFalse(AtRestNarrativeField::isSealed($row->{$column} ?? null), "Expected {$table}.{$column} to be plaintext.");
        $this->assertSame($expectedPlaintext, $row->{$column} ?? null);
    }
}
