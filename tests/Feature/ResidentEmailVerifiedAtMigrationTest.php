<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * SR-03 migration: adds nullable resident_accounts.email_verified_at; up/down are idempotent and never
 * change existing rows (household links via resident_id included). sqlite :memory: only.
 */
class ResidentEmailVerifiedAtMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/migrations/2026_10_30_090000_add_email_verified_at_to_resident_accounts_table.php';

    private function migration(): Migration
    {
        return require base_path(self::FILE);
    }

    /** ERD-shaped resident_accounts without the new column, holding a linked and an unlinked account. */
    private function seedPreMigrationAccounts(): array
    {
        ClientTestingErdSchema::ensure();
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('resident_id')->nullable()->unique();
        });

        foreach ([['linked@example.test', 42], ['unlinked@example.test', null]] as [$email, $residentId]) {
            DB::table('resident_accounts')->insert([
                'resident_id' => $residentId, 'first_name' => 'Res', 'middle_name' => 'I', 'last_name' => 'Dent', 'zone_purok' => '3',
                'email' => $email, 'password' => bcrypt('SafePassw0rd!x'), 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
            ]);
        }

        return DB::table('resident_accounts')->orderBy('account_id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function rowsWithout(string $column): array
    {
        return DB::table('resident_accounts')->orderBy('account_id')->get()
            ->map(function ($r) use ($column) {
                $row = (array) $r;
                unset($row[$column]);

                return $row;
            })->all();
    }

    public function test_up_adds_a_nullable_column_and_preserves_every_row(): void
    {
        $before = $this->seedPreMigrationAccounts();
        $this->assertFalse(Schema::hasColumn('resident_accounts', 'email_verified_at'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('resident_accounts', 'email_verified_at'));
        $this->assertSame($before, $this->rowsWithout('email_verified_at'));
        $this->assertSame(2, DB::table('resident_accounts')->whereNull('email_verified_at')->count());
        $this->assertSame(42, (int) DB::table('resident_accounts')->where('email', 'linked@example.test')->value('resident_id'));

        // Nullable: a new row without the column still inserts.
        DB::table('resident_accounts')->insert([
            'first_name' => 'New', 'last_name' => 'Row', 'email' => 'new@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull(DB::table('resident_accounts')->where('email', 'new@example.test')->value('email_verified_at'));
    }

    public function test_up_is_idempotent(): void
    {
        $before = $this->seedPreMigrationAccounts();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($before, $this->rowsWithout('email_verified_at'));
    }

    public function test_down_removes_only_the_column_and_is_idempotent(): void
    {
        $before = $this->seedPreMigrationAccounts();
        $this->migration()->up();
        DB::table('resident_accounts')->where('email', 'unlinked@example.test')->update(['email_verified_at' => now()]);

        $this->migration()->down();
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('resident_accounts', 'email_verified_at'));
        $this->assertSame($before, DB::table('resident_accounts')->orderBy('account_id')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_migration_skips_when_the_table_does_not_exist(): void
    {
        Schema::dropIfExists('resident_accounts');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('resident_accounts'));
    }
}
