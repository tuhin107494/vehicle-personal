<?php

namespace App\Models\Billing;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** How much of a limited feature a shop has used in a period. Counted in the cache, written here periodically. */
class FeatureUsage extends Model
{
    use BelongsToShop;

    protected $table = 'feature_usage';

    protected $fillable = ['shop_id', 'feature_key', 'used', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return ['used' => 'integer', 'period_start' => 'date', 'period_end' => 'date'];
    }
}
