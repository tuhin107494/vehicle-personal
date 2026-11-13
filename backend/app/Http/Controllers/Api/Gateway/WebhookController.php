<?php

namespace App\Http\Controllers\Api\Gateway;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyPaymentAttempt;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayLog;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Scopes\ShopScope;
use App\Services\Gateway\GatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where gateways call back. Nothing here trusts the payload: the signature is checked, the raw body is kept, the
 * attempt is queued for verification against the gateway's API, and the answer is 200 at once.
 */
class WebhookController extends Controller
{
    public function __construct(private GatewayRegistry $registry) {}

    /** Server-to-server notification (IPN / webhook). */
    public function webhook(Request $request, string $code): JsonResponse
    {
        $this->receive($request, $code);

        return $this->success(null, 'Received');
    }

    /** The payer's browser coming back from the gateway: same handling, then on to the web app's payment page. */
    public function callback(Request $request, string $code): RedirectResponse
    {
        $attempt = $this->receive($request, $code);
        $app = rtrim(config('app.frontend_url'), '/');

        return redirect()->away($attempt ? "$app/billing/payments/{$attempt->id}?result=".$request->query('result', 'returned') : "$app/billing");
    }

    private function receive(Request $request, string $code): ?PaymentAttempt
    {
        $config = GatewayConfig::where('code', $code)->first();

        if (! $config || ! GatewayRegistry::knows($code)) {
            abort(404);
        }

        if (! $config->allowsWebhookFrom($request->ip())) {
            GatewayLog::create(['gateway_code' => $code, 'direction' => 'webhook', 'action' => 'rejected_ip', 'ip' => $request->ip(), 'payload' => GatewayLog::scrub($request->all())]);
            abort(403, 'This address may not call this endpoint.');
        }

        $result = $this->registry->make($config)->handleWebhook($request);
        $attempt = $result->valid ? $this->find($result->idempotencyKey, $result->gatewayPaymentId, $code) : null;

        GatewayLog::create([
            'shop_id' => $attempt?->shop_id, 'attempt_id' => $attempt?->id, 'gateway_code' => $code, 'direction' => 'webhook',
            'action' => $result->valid ? ($attempt ? 'received' : 'unmatched') : 'invalid', 'ip' => $request->ip(),
            'payload' => GatewayLog::scrub($result->raw ?: $request->all()) + ($result->reason ? ['_reason' => $result->reason] : []),
        ]);

        if (! $result->valid) {
            abort(400, 'The notification could not be verified.');
        }

        if ($attempt && ! $attempt->isFinal()) {
            VerifyPaymentAttempt::dispatch($attempt->id);
        }

        return $attempt;
    }

    private function find(?string $key, ?string $gatewayPaymentId, string $code): ?PaymentAttempt
    {
        // no signed-in user here: the attempt is found by its keys, limited to this gateway
        $query = PaymentAttempt::withoutGlobalScope(ShopScope::class)->where('gateway_code', $code);

        return match (true) {
            filled($key) => $query->where('idempotency_key', $key)->first(),
            filled($gatewayPaymentId) => $query->where('gateway_payment_id', $gatewayPaymentId)->first(),
            default => null,
        };
    }
}
