<?php

namespace App\Casts;

use App\Support\AtRestColumns;
use App\Support\AtRestRecord;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for AtRestColumns entries: seals on write, opens on read.
 * Flag columns read back as real booleans, matching the previous 'boolean' cast.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class AtRestEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $opened = AtRestRecord::open($value, $model->getTable(), $key);

        return AtRestColumns::type($model->getTable(), $key) === AtRestColumns::BOOL
            ? (bool) $opened
            : $opened;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return AtRestRecord::seal($value, $model->getTable(), $key);
    }
}
