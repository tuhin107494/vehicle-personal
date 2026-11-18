<?php

namespace App\Services\Gateway;

use App\Events\PaymentSucceeded;
use App\Models\Gateway\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hands pending outbox rows to Laravel's event system, one at a time, each claimed under a lock so two publishers never
 * send the same event. A listener that throws leaves the row pending for the next run, up to a limit.
 */
class OutboxPublisher
{
    public const BATCH = 100;

    public const MAX_ATTEMPTS = 10;

    /** Publishes up to one batch. Returns how many were sent. */
    public function publish(): int
    {
        $sent = 0;

        foreach (OutboxEvent::where('status', 'pending')->orderBy('id')->limit(self::BATCH)->pluck('id') as $id) {
            $sent += $this->publishOne($id) ? 1 : 0;
        }

        return $sent;
    }

    private function publishOne(int $id): bool
    {
        $event = DB::transaction(function () use ($id) {
            $event = OutboxEvent::lockForUpdate()->find($id);

            if (! $event || $event->status !== 'pending') {
                return null; // another publisher took it
            }

            $event->increment('attempts');

            return $event;
        });

        if (! $event) {
            return false;
        }

        try {
            $this->dispatch($event);
            $event->update(['status' => 'published', 'published_at' => now(), 'last_error' => null]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Outbox event failed', ['event_id' => $event->id, 'event_type' => $event->event_type, 'shop_id' => $event->payload['shop_id'] ?? null, 'attempt' => $event->attempts, 'error' => $e->getMessage()]);
            $event->update(['last_error' => mb_substr($e->getMessage(), 0, 255), 'status' => $event->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending']);

            return false;
        }
    }

    private function dispatch(OutboxEvent $event): void
    {
        match ($event->event_type) {
            OutboxEvent::PAYMENT_SUCCEEDED => PaymentSucceeded::dispatch($event->payload),
            default => null, // subscription.changed and payment.refunded have no listener yet; they are kept for the record
        };
    }
}
