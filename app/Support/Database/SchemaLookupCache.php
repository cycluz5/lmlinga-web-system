<?php

namespace App\Support\Database;

use Closure;

/**
 * Per-application memo of table / column existence lookups.
 *
 * Presenters and dashboard statistics probe the schema once per resident row.
 * On MariaDB each column probe reads information_schema (~5 ms), so identical
 * lookups were costing tens of seconds per page. The schema does not change
 * mid-request; any DDL statement, rollback or migration run flushes the memo
 * (wired in AppServiceProvider).
 */
final class SchemaLookupCache
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /**
     * Failed lookups throw and are never stored, so a database outage is not
     * remembered as "table missing".
     *
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if (array_key_exists($key, $this->entries)) {
            return $this->entries[$key];
        }

        return $this->entries[$key] = $resolve();
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    public static function changesSchema(string $sql): bool
    {
        return preg_match('/^\s*(create|alter|drop|rename)\s/i', $sql) === 1;
    }
}
