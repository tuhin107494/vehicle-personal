<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A gateway payment was confirmed. Carries the outbox payload: payment_id, attempt_id, invoice_id, shop_id, amount_paisa, gateway. */
class PaymentSucceeded
{
    use Dispatchable;

    /** @param  array<string, mixed>  $payload  keys: payment_id, attempt_id, invoice_id, shop_id, amount_paisa, gateway */
    public function __construct(public array $payload) {}
}
