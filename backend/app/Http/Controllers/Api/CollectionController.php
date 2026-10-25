<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The due lists: what each customer owes and when, as an overdue list and as what falls due in the next 3, 7 and 10 days.
 * "Installment" rows come from payment plans; "promise" rows are normal (non-installment) dues with a promised pay date.
 */
class CollectionController extends Controller implements HasMiddleware
{
    /** bucket key => [label, first day offset, last day offset] (null = no lower limit). Upcoming windows start today. */
    private const BUCKETS = [
        'overdue' => ['Overdue', null, -1],
        'd3' => ['Next 3 days', 0, 3],
        'd7' => ['Next 7 days', 0, 7],
        'd10' => ['Next 10 days', 0, 10],
    ];

    private const KINDS = ['installment', 'promise'];

    public static function middleware(): array
    {
        return [new Middleware('permission:collections.view')];
    }

    public function summary(): JsonResponse
    {
        $rows = $this->rows();

        $stats = fn (Collection $in) => [
            'count' => $in->count(),
            'amount' => round($in->sum('amount'), 2),
            'customers' => $in->pluck('customer.id')->unique()->count(),
        ];

        $buckets = collect(self::BUCKETS)->map(function ($b, $key) use ($rows, $stats) {
            $in = $rows->filter(fn ($r) => $this->inBucket($r['days_left'], $key));

            return ['key' => $key, 'label' => $b[0]] + $stats($in) + [
                'kinds' => collect(self::KINDS)->mapWithKeys(fn ($k) => [$k => $stats($in->where('type', $k))])->all(),
            ];
        })->values();

        return $this->success([
            'buckets' => $buckets,
            // What should be collected right now: everything overdue plus what falls due today.
            'current_due' => round($rows->where('days_left', '<=', 0)->sum('amount'), 2),
        ]);
    }

    public function upcoming(Request $request): JsonResponse
    {
        $bucket = $request->input('bucket', 'overdue');
        abort_unless(isset(self::BUCKETS[$bucket]), 422, 'Unknown list.');

        $kind = $request->input('kind');
        abort_unless($kind === null || $kind === '' || in_array($kind, self::KINDS, true), 422, 'Unknown kind.');

        $rows = $this->rows()
            ->filter(fn ($r) => $this->inBucket($r['days_left'], $bucket))
            ->when($kind, fn ($c) => $c->where('type', $kind))
            ->values();

        return $this->success($rows, 'OK', 200, ['total' => $rows->count(), 'total_amount' => round($rows->sum('amount'), 2)]);
    }

    private function inBucket(int $daysLeft, string $key): bool
    {
        [, $from, $to] = self::BUCKETS[$key];

        return ($from === null || $daysLeft >= $from) && $daysLeft <= $to;
    }

    /** @return Collection<int,array<string,mixed>> every unpaid installment and open normal due that is overdue or due within 10 days */
    private function rows(): Collection
    {
        $limit = today()->addDays(10)->toDateString();

        $installments = Installment::with(['plan.sale.customer', 'plan.sale.vehicle'])
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereDate('due_date', '<=', $limit)
            ->whereHas('plan.sale', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (Installment $i) => $this->row('installment', $i->id, "Installment #{$i->seq_no}", $i->plan->sale, $i->due_date, $i->remaining()));

        $promises = DuePromise::with(['sale.customer', 'sale.vehicle'])
            ->whereIn('status', ['open', 'broken'])
            ->whereDate('promised_date', '<=', $limit)
            ->whereHas('sale', fn ($q) => $q->where('status', 'active')->where('due_amount', '>', 0))
            ->get()
            ->map(fn (DuePromise $p) => $this->row('promise', $p->id, 'Normal due', $p->sale, $p->promised_date, min($p->promised_amount, $p->sale->due_amount)));

        $rows = $installments->concat($promises);
        $totals = Sale::where('status', 'active')->whereIn('customer_id', $rows->pluck('customer.id')->unique())
            ->selectRaw('customer_id, SUM(due_amount) as total')->groupBy('customer_id')->pluck('total', 'customer_id');

        return $rows->map(fn (array $r) => $this->withCustomerTotal($r, $totals))
            ->sortBy('due_date')->values();
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  Collection<int|string,mixed>  $totals  customer id => everything they owe across active sales
     * @return array<string,mixed>
     */
    private function withCustomerTotal(array $row, Collection $totals): array
    {
        return $row + ['customer_total_due' => round((float) ($totals[$row['customer']['id']] ?? 0), 2)];
    }

    /** @return array<string,mixed> */
    private function row(string $type, int $id, string $label, Sale $sale, Carbon $dueDate, float $amount): array
    {
        $due = $dueDate->copy()->startOfDay();
        $daysLeft = (int) today()->diffInDays($due, false);

        return [
            'type' => $type, 'id' => $id, 'label' => $label, 'sale_id' => $sale->id,
            'due_date' => $due->toDateString(),
            'days_left' => $daysLeft,
            'overdue_by' => $daysLeft < 0 ? $this->overdueBy($due) : null,
            'amount' => round($amount, 2),
            'sale_due' => $sale->due_amount,
            'guarantor_phone' => $sale->guarantor_phone,
            'customer' => $sale->customer->only(['id', 'name', 'phone', 'alt_phone', 'address']),
            'vehicle' => $sale->vehicle->only(['id', 'chassis_no', 'brand', 'model']),
        ];
    }

    /** How long past the due date: whole months, the days left over, and the total days. */
    private function overdueBy(Carbon $due): array
    {
        $gap = $due->diff(today());

        return ['months' => $gap->y * 12 + $gap->m, 'days' => $gap->d, 'total_days' => (int) $due->diffInDays(today())];
    }
}
