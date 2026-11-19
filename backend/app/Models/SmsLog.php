<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One SMS: a due alert (a due and a stage) or a test. Every attempt is kept, with its cost and the gateway's answer.
 *
 * @property Carbon|null $sent_at
 */
class SmsLog extends Model
{
    use BelongsToShop;

    public const STAGES = ['upcoming', 'today', 'overdue', 'final'];

    protected $fillable = [
        'shop_id',
        'customer_id',
        'sale_id',
        'due_type',
        'due_id',
        'stage',
        'phone',
        'message',
        'parts',
        'cost',
        'provider',
        'provider_msg_id',
        'status',
        'response',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['shop_id' => 'integer', 'due_id' => 'integer', 'parts' => 'integer', 'cost' => 'float', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** The number as staff see it: 017••••45678. */
    public function maskedPhone(): string
    {
        $local = str_starts_with($this->phone, '880') ? substr($this->phone, 2) : $this->phone;

        return strlen($local) >= 8 ? substr($local, 0, 3).'••••'.substr($local, -5) : $local;
    }
}
