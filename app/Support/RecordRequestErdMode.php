<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative ERD record_requests contract helpers.
 */
final class RecordRequestErdMode
{
    private static ?bool $requiresAccountId = null;

    public static function requiresAccountId(): bool
    {
        if (self::$requiresAccountId === null) {
            self::$requiresAccountId = self::detectRequiresAccountId();
        }

        return self::$requiresAccountId;
    }

    public static function accountForeignTable(): string
    {
        return 'resident_accounts';
    }

    public static function accountForeignKey(): string
    {
        return UserManagementErdMode::residentAccountKeyName();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function isWritablePayloadValid(array $payload): bool
    {
        if (! self::requiresAccountId()) {
            return true;
        }

        return isset($payload['account_id']) && (int) $payload['account_id'] > 0;
    }

    public static function resetCachedState(): void
    {
        self::$requiresAccountId = null;
    }

    private static function detectRequiresAccountId(): bool
    {
        if (! Schema::hasTable('record_requests') || ! Schema::hasColumn('record_requests', 'account_id')) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT IS_NULLABLE, COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['record_requests', 'account_id']
            );

            return $row !== null
                && strtoupper((string) ($row->IS_NULLABLE ?? '')) === 'NO'
                && ($row->COLUMN_DEFAULT === null);
        }

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA table_info('record_requests')") as $column) {
                if (($column->name ?? '') === 'account_id') {
                    return (int) ($column->notnull ?? 0) === 1;
                }
            }
        }

        return false;
    }
}
