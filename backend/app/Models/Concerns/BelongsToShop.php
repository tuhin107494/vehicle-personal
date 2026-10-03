<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation: every query is limited to the signed-in user's shop and
 * new rows are stamped with it. Console/queue code (no signed-in user) is unscoped.
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope);

        static::creating(function ($model) {
            if (! $model->shop_id && ($user = auth()->user()) && $user->shop_id) {
                $model->shop_id = $user->shop_id;
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
