<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Builder;

/**
 * Schema builder behind the Schema facade that answers hasTable / hasColumn
 * from {@see SchemaLookupCache}. Every other call goes to the real builder.
 *
 * @mixin Builder
 */
class CachingSchemaBuilder
{
    public function __construct(
        private readonly Builder $builder,
        private readonly SchemaLookupCache $cache,
    ) {}

    public function hasTable($table): bool
    {
        return $this->cache->remember(
            $this->key('table', $table),
            fn (): bool => $this->builder->hasTable($table),
        );
    }

    /**
     * @return list<string>
     */
    public function getColumnListing($table): array
    {
        return $this->cache->remember(
            $this->key('columns', $table),
            fn (): array => $this->builder->getColumnListing($table),
        );
    }

    public function hasColumn($table, $column): bool
    {
        return in_array(strtolower($column), $this->lowerColumns($table), true);
    }

    /**
     * @param  array<string>  $columns
     */
    public function hasColumns($table, array $columns): bool
    {
        $tableColumns = $this->lowerColumns($table);

        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $tableColumns, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->builder->{$method}(...$parameters);
    }

    /**
     * @return list<string>
     */
    private function lowerColumns(string $table): array
    {
        return array_map(strtolower(...), $this->getColumnListing($table));
    }

    private function key(string $kind, string $table): string
    {
        return $this->builder->getConnection()->getName().'|'.$kind.'|'.$table;
    }
}
