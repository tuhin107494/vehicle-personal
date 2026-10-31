<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Extra money a customer owes on a sale.
 *
 * @property Carbon $charged_on
 */
class SaleCharge extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'sale_id', 'purpose', 'amount', 'charged_on', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'charged_on' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
