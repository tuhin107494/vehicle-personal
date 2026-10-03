<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** A user sees the platform's categories plus the ones their own shop added. */
class CategoryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $builder->where(function ($q) use ($user) {
            $q->whereNull('vehicle_categories.shop_id');

            if ($user->shop_id) {
                $q->orWhere('vehicle_categories.shop_id', $user->shop_id);
            }
        });
    }
}
