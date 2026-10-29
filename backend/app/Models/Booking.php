<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle held for a customer who paid an advance. It ends as completed (a sale is made), cancelled, or expired.
 *
 * @property Carbon $booked_on
 * @property Carbon $expires_on
 */
class Booking extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'vehicle_id',
        'customer_id',
        'agreed_price',
        'advance_amount',
        'advance_method',
        'booked_on',
        'expires_on',
        'status',
        'sale_id',
        'refund_amount',
        'kept_amount',
        'closed_on',
        'note',
        'close_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'closed_on' => 'date:Y-m-d',
            'agreed_price' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'kept_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
}
