<?php

namespace App\Http\Controllers\Api\Billing\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\GatewayRefund;
use App\Models\Scopes\ShopScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The platform's money over a period, super admin only: what came in through each gateway, what it cost, what went
 * back, month by month; plus where the shops stand today (subscriptions by status, plans, recurring revenue, dues).
 * Sums are done in SQL per day and folded into months here, which works the same on MySQL and SQLite.
 */
class RevenueReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = ($request->from ? Carbon::parse($request->from) : today()->startOfYear())->startOfDay();
        $to = ($request->to ? Carbon::parse($request->to) : today())->endOfDay();

        $payments = GatewayPayment::withoutGlobalScope(ShopScope::class)->whereBetween('paid_at', [$from, $to])
            ->selectRaw('gateway_code, DATE(paid_at) as d, COUNT(*) as n, SUM(amount_paisa) as amount, SUM(fee_paisa) as fee')
            ->groupBy('gateway_code', 'd')->toBase()->get();
        $refunds = GatewayRefund::where('status', 'success')->whereBetween('updated_at', [$from, $to])
            ->selectRaw('DATE(updated_at) as d, SUM(amount_paisa) as amount')->groupBy('d')->toBase()->get();

        $gross = (int) $payments->sum('amount');
        $fees = (int) $payments->sum('fee');
        $refunded = (int) $refunds->sum('amount');

        $byMonth = [];
        foreach ($payments as $row) {
            $m = substr((string) $row->d, 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? ['month' => $m, 'payments' => 0, 'amount_paisa' => 0, 'fee_paisa' => 0, 'refunded_paisa' => 0]);
            $byMonth[$m]['payments'] += (int) $row->n;
            $byMonth[$m]['amount_paisa'] += (int) $row->amount;
            $byMonth[$m]['fee_paisa'] += (int) $row->fee;
        }
        foreach ($refunds as $row) {
            $m = substr((string) $row->d, 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? ['month' => $m, 'payments' => 0, 'amount_paisa' => 0, 'fee_paisa' => 0, 'refunded_paisa' => 0]);
            $byMonth[$m]['refunded_paisa'] += (int) $row->amount;
        }
        ksort($byMonth);

        $byGateway = $payments->groupBy('gateway_code')->map(fn ($rows, $code) => ['gateway' => $code, 'payments' => (int) $rows->sum('n'), 'amount_paisa' => (int) $rows->sum('amount'), 'fee_paisa' => (int) $rows->sum('fee')])->values();

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => ['payments' => (int) $payments->sum('n'), 'amount_paisa' => $gross, 'fee_paisa' => $fees, 'refunded_paisa' => $refunded, 'net_paisa' => $gross - $fees - $refunded],
            'by_gateway' => $byGateway,
            'by_month' => array_values($byMonth),
            'subscriptions' => $this->subscriptions(),
            'outstanding' => ['invoices' => Invoice::withoutGlobalScope(ShopScope::class)->where('status', 'unpaid')->where('total_paisa', '>', 0)->count(), 'amount_paisa' => (int) Invoice::withoutGlobalScope(ShopScope::class)->where('status', 'unpaid')->sum('total_paisa')],
        ]);
    }

    /** Where every shop stands now: counts by status, shops per plan, and the monthly recurring revenue of the running ones. */
    private function subscriptions(): array
    {
        $latest = Subscription::withoutGlobalScope(ShopScope::class)->selectRaw('MAX(id)')->groupBy('shop_id');
        $base = Subscription::withoutGlobalScope(ShopScope::class)->whereIn('id', $latest);
        $byPlan = (clone $base)->whereIn('status', ['trialing', 'active', 'past_due'])->selectRaw('plan_id, status, COUNT(*) as n')->groupBy('plan_id', 'status')->toBase()->get();
        $plans = Plan::whereIn('id', $byPlan->pluck('plan_id'))->get()->keyBy('id');
        $mrr = 0;
        $perPlan = [];

        foreach ($byPlan as $row) {
            $plan = $plans[$row->plan_id] ?? null;
            if (! $plan) {
                continue;
            }
            $perPlan[$plan->id] ??= ['plan' => $plan->name, 'shops' => 0, 'paying' => 0];
            $perPlan[$plan->id]['shops'] += (int) $row->n;
            if ($row->status === 'active') {
                $perPlan[$plan->id]['paying'] += (int) $row->n;
                $mrr += intdiv($plan->price_paisa, $plan->periodMonths()) * (int) $row->n;
            }
        }

        return [
            'by_status' => (clone $base)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'by_plan' => array_values($perPlan),
            'mrr_paisa' => $mrr,
        ];
    }
}
