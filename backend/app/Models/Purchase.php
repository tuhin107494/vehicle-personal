<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $purchase_date
 */
class Purchase extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'vehicle_id',
        'supplier_id',
        'customer_id',
        'source',
        'memo_no',
        'purchase_date',
        'price',
        'paid_amount',
        'due_adjustment',
        'adjusted_sale_id',
        'cost_extra',
        'payment_status',
        'notes',
        'created_by',
        'payment_method',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'price' => 'float',
            'paid_amount' => 'float',
            'cost_extra' => 'float',
            'due_adjustment' => 'float',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Set when a second-hand vehicle was bought back from a customer.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Whoever sold it to us: the supplier, or the customer for a buy-back. */
    public function seller(): Customer|Supplier|null
    {
        return $this->source === 'customer' ? $this->customer : $this->supplier;
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchasePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
