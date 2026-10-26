<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\ShopSettings;

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
            'amount' => Money::sum($in, 'amount'),
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
            'current_due' => Money::sum($rows->where('days_left', '<=', 0), 'amount'),
            // everything customers still owe on active sales, as on the dashboard
            'total_due' => round((float) Sale::where('status', 'active')->sum('due_amount'), 2),
            'recall_days' => ShopSettings::recallDays(),
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

        return $this->success($rows, 'OK', 200, ['total' => $rows->count(), 'total_amount' => Money::sum($rows, 'amount')]);
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
            ->map(fn (DuePromise $p) => $this->row('promise', $p->id, 'Due', $p->sale, $p->promised_date, min($p->promised_amount, $p->sale->due_amount)));

        $rows = $installments->concat($promises);
        $totals = Sale::where('status', 'active')->whereIn('customer_id', $rows->pluck('customer.id')->unique())
            ->selectRaw('customer_id, SUM(due_amount) as total')->groupBy('customer_id')->pluck('total', 'customer_id');

        $calls = $this->recentCalls();

        return $rows->map(fn (array $r) => $this->withCustomerTotal($r, $totals) + [
                'called_until' => $calls[$r['type'].':'.$r['id']]['until'] ?? null,
                'called_meet_date' => $calls[$r['type'].':'.$r['id']]['meet_date'] ?? null,
                'called_note' => $calls[$r['type'].':'.$r['id']]['note'] ?? null,
            ])
            ->sortBy('due_date')->values();
    }

    /** @return array<string,array{until:string,meet_date:?string,note:?string}> "kind:id" => the day the row is due for another call, for rows called recently */
    private function recentCalls(): array
    {
        $days = ShopSettings::recallDays();

        // the latest call on a row wins; the customer's meeting day, when they gave one, is the day to call again
        return CallLog::where('called_at', '>=', today()->subDays(360)->startOfDay())
            ->orderBy('called_at')->get()
            ->mapWithKeys(fn (CallLog $c) => [$c->kind.':'.$c->ref_id => [
                'until' => ($c->meet_date ?? $c->called_at->copy()->startOfDay()->addDays($days))->toDateString(),
                'meet_date' => $c->meet_date?->toDateString(),
                'note' => $c->note,
            ]])
            ->filter(fn (array $c) => $c['until'] > today()->toDateString())
            ->all();
    }

    /** Mark a row "call done": it stays in the lists, marked as called, until the recall days pass. */
    public function markCalled(Request $request): JsonResponse
    {
        $data = $this->target($request);

        $extra = $request->validate(['meet_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(360)->toDateString()], 'note' => ['nullable', 'string', 'max:500']]);

        CallLog::create($data + $extra + ['called_by' => $request->user()->id, 'called_at' => now()]);

        return $this->success(null, 'Call marked done');
    }

    /** Undo a "call done" mark so the row is due for a call again. */
    public function unmarkCalled(Request $request): JsonResponse
    {
        CallLog::where($this->target($request))->delete();

        return $this->success(null, 'Call mark removed');
    }

    /** @return array{kind:string,ref_id:int} */
    private function target(Request $request): array
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', self::KINDS)], 'id' => ['required', 'integer']]);
        $exists = $data['type'] === 'installment' ? Installment::whereKey($data['id'])->exists() : DuePromise::whereKey($data['id'])->exists();
        abort_unless($exists, 404, 'Not found.');

        return ['kind' => $data['type'], 'ref_id' => (int) $data['id']];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  Collection<int|string,mixed>  $totals  customer id => everything they owe across active sales
     * @return array<string,mixed>
     */
    private function withCustomerTotal(array $row, Collection $totals): array
    {
        return $row + ['customer_total_due' => (float) ($totals[$row['customer']['id']] ?? 0)];
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
            'amount' => $amount,
            'sale_due' => $sale->due_amount,
            'guarantor_phone' => $sale->guarantor_phone,
            'customer' => $sale->customer->only(['id', 'name', 'phone', 'alt_phone', 'address']),
            'vehicle' => $sale->vehicle->only(['id', 'chassis_no', 'engine_no', 'brand', 'model']),
        ];
    }

    /** How long past the due date: whole months, the days left over, and the total days. */
    private function overdueBy(Carbon $due): array
    {
        $gap = $due->diff(today());

        return ['months' => $gap->y * 12 + $gap->m, 'days' => $gap->d, 'total_days' => (int) $due->diffInDays(today())];
    }
}
