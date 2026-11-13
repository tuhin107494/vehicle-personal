<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Something a plan switches on (boolean) or caps (limit). The keys the code checks are listed in FeatureCatalog.
 *
 * @property-read Pivot|null $pivot set when loaded through a plan: its value for that plan
 */
class Feature extends Model
{
    protected $fillable = ['key', 'type', 'description'];

    /** @return BelongsToMany<Plan, $this> */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features')->withPivot('value');
    }
}
