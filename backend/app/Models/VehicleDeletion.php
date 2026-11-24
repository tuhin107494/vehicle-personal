<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deleted vehicle: its numbers and a summary of what was removed with it.
 *
 * @property array<string, mixed> $summary
 */
class VehicleDeletion extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'vehicle_id', 'chassis_no', 'engine_no', 'registration_no', 'vehicle_name', 'summary', 'deleted_by'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
