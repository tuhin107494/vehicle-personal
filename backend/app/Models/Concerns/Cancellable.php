<?php

namespace App\Models\Concerns;

use App\Models\Scopes\NotCancelledScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * A row that is cancelled instead of deleted: it keeps its amount, who cancelled it and why, but is left out of every query
 * unless asked for with onlyCancelled().
 */
trait Cancellable
{
    public static function bootCancellable(): void
    {
        static::addGlobalScope(new NotCancelledScope);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnlyCancelled(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotCancelledScope::class)->whereNotNull($this->getTable().'.cancelled_at');
    }
}
