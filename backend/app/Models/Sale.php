<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'vehicle_id',
        'purchase_id',
        'party_id',
        'sale_date',
        'sale_price',
        'down_payment',
        'payment_type',
        'total_paid',
        'due_amount',
        'payment_status',
        'status',
        'cancelled_at',
        'cancel_reason',
        'investor_id',
        'registration_status',
        'created_by',
        'guarantor_phone',
    ];

    public const REGISTRATION_STEPS = [
        'pending',
        'bank_chalan_done',
        'file_received',
        'file_processing',
        'registration_complete',
        'document_provided',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date:Y-m-d',
            'sale_price' => 'float',
            'down_payment' => 'float',
            'total_paid' => 'float',
            'due_amount' => 'float',
            'cancelled_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function plan(): HasOne
    {
        return $this->hasOne(InstallmentPlan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function promises(): HasMany
    {
        return $this->hasMany(DuePromise::class);
    }

    public function registrationLogs(): HasMany
    {
        return $this->hasMany(RegistrationLog::class);
    }

    public function grandTotal(): float
    {
        if ($this->payment_type === 'installment' && $this->plan) {
            return round($this->down_payment + $this->plan->total_payable, 2);
        }

        return (float) $this->sale_price;
    }
}
