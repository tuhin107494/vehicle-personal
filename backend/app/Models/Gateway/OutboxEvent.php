<?php

namespace App\Models\Gateway;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An event written in the same transaction as the change it reports (transactional outbox). The publisher job hands
 * pending rows to Laravel's event system afterwards; that is the only way the payment and subscription modules talk.
 *
 * @property Carbon|null $published_at
 * @property array<string, mixed> $payload
 */
class OutboxEvent extends Model
{
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const PAYMENT_REFUNDED = 'payment.refunded';

    public const SUBSCRIPTION_CHANGED = 'subscription.changed';

    protected $fillable = ['event_type', 'payload', 'status', 'attempts', 'last_error', 'published_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'published_at' => 'datetime'];
    }

    /** Queue an event inside the caller's transaction. */
    public static function record(string $type, array $payload): self
    {
        return self::create(['event_type' => $type, 'payload' => $payload]);
    }
}
