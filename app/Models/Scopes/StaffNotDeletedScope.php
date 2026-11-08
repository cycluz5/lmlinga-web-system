<?php

namespace App\Models\Scopes;

use App\Support\UserManagementErdMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Exclude staff rows with deleted_at set, only when the column exists.
 * Hybrid Laravel/ERD identity cannot always use Eloquent SoftDeletes.
 */
final class StaffNotDeletedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! UserManagementErdMode::staffHasDeletedAt()) {
            return;
        }

        $builder->whereNull($model->getTable().'.deleted_at');
    }
}
