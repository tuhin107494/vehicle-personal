<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Models\Billing\Invoice;
use App\Models\Scopes\ShopScope;
use App\Services\Billing\SubscriptionService;

/** The subscription module's side of a payment: the paid invoice starts or extends the shop's period and clears its cached entitlements. */
class ExtendSubscriptionOnPayment
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function handle(PaymentSucceeded $event): void
    {
        $invoice = Invoice::withoutGlobalScope(ShopScope::class)->findOrFail($event->payload['invoice_id']);
        $this->subscriptions->renew($invoice);
    }
}
