<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Set when a second-hand vehicle was bought back from a customer. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Whoever sold it to us: the supplier, or the customer for a buy-back. */
    public function seller(): ?Model
    {
        return $this->source === 'customer' ? $this->customer : $this->supplier;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
