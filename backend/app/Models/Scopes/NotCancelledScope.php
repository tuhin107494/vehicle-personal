<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Hides cancelled payments, so every total, list and balance in the app skips them without each query asking. */
class NotCancelledScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.cancelled_at');
    }
}
