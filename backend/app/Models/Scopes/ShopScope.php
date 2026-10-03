<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ShopScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user) {
            // A signed-in user without a shop (super admin) sees no shop data at all.
            $builder->where($model->getTable().'.shop_id', $user->shop_id ?? 0);
        }
    }
}
