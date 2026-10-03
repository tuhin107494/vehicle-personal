<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestorResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SaleResource;
use App\Http\Resources\VehicleResource;
use App\Models\Advance;
use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\Vehicle;
use App\Services\InvestorPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reports.view'),
        ];
    }

    public function stock(): JsonResponse
    {
        $vehicles = Vehicle::with('currentPurchase.party')->where('status', 'in_stock')->limit(1000)->get(); // a safety cap, far above any real stock

        return $this->success([
            'total_count' => $vehicles->count(),
            'total_cost' => round($vehicles->sum(fn ($v) => ($v->currentPurchase->price ?? 0) + ($v->currentPurchase->cost_extra ?? 0)), 2),
            'vehicles' => $vehicles->map(fn ($v) => [
                ...VehicleResource::make($v)->resolve(),
                'days_in_stock' => $v->currentPurchase ? (int) $v->currentPurchase->purchase_date->diffInDays(today()) : null,
            ]),
        ]);
    }

    public function dues(): JsonResponse
    {
        $sales = Sale::with(['vehicle', 'party', 'plan.installments', 'promises'])
            ->where('status', 'active')->where('due_amount', '>', 0)->orderByDesc('due_amount')->limit(1000)->get(); // a safety cap

        return $this->success([
            'total_due' => round($sales->sum('due_amount'), 2),
            'sales' => $sales->map(fn ($s) => [
                ...SaleResource::make($s)->resolve(),
                'next_due_date' => $s->payment_type === 'installment'
                    ? $s->plan?->installments->whereIn('status', ['pending', 'partial', 'overdue'])->sortBy('seq_no')->first()?->due_date?->toDateString()
                    : $s->promises->where('status', 'open')->sortBy('promised_date')->first()?->promised_date?->toDateString(),
            ]),
        ]);
    }

    public function investors(InvestorPortfolioService $portfolio): JsonResponse
    {
        return $this->success(Investor::orderBy('name')->get()->map(fn ($i) => [
            'investor' => InvestorResource::make($i)->resolve(),
            'summary' => $portfolio->summary($i),
        ]));
    }

    /** Purchases in a date range: totals, a breakdown by vehicle type and the list itself. */
    public function purchases(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $purchases = Purchase::with(['vehicle', 'party'])
            ->when($request->from, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->when($request->vehicle_type, fn ($q, $t) => $q->whereHas('vehicle', fn ($q) => $q->where('vehicle_type', $t)))
            ->when($request->party_id, fn ($q, $id) => $q->where('party_id', $id))
            ->when($request->source, fn ($q, $src) => $q->where('source', $src))
            ->orderByDesc('purchase_date')->orderByDesc('id')->get();

        $rows = $purchases->map(fn ($p) => [
            ...PurchaseResource::make($p)->resolve(),
            'total_cost' => round($p->price + $p->cost_extra, 2),
            'unpaid' => round(max($p->price - $p->paid_amount, 0), 2),
        ]);

        return $this->success([
            'totals' => [
                'count' => $purchases->count(),
                'price' => round($purchases->sum('price'), 2),
                'extra_cost' => round($purchases->sum('cost_extra'), 2),
                'total_cost' => round($purchases->sum(fn ($p) => $p->price + $p->cost_extra), 2),
                'paid' => round($purchases->sum('paid_amount'), 2),
                'unpaid' => round($rows->sum('unpaid'), 2),
            ],
            'by_type' => $purchases->groupBy(fn ($p) => $p->vehicle->vehicle_type)->map(fn ($g, $type) => [
                'type' => $type, 'count' => $g->count(), 'amount' => round($g->sum(fn ($p) => $p->price + $p->cost_extra), 2),
            ])->values(),
            'purchases' => $rows->values(),
        ]);
    }

    /** Sales in a date range: totals (cancelled sales are listed but not counted), profit, a breakdown by type and the list. */
    public function sales(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $sales = Sale::with(['vehicle', 'party', 'purchase', 'plan'])
            ->when($request->from, fn ($q, $v) => $q->whereDate('sale_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('sale_date', '<=', $v))
            ->when($request->vehicle_type, fn ($q, $t) => $q->whereHas('vehicle', fn ($q) => $q->where('vehicle_type', $t)))
            ->when($request->payment_type, fn ($q, $t) => $q->where('payment_type', $t))
            ->when($request->party_id, fn ($q, $id) => $q->where('party_id', $id))
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->orderByDesc('sale_date')->orderByDesc('id')->get();

        $cost = fn ($s) => round(($s->purchase->price ?? 0) + ($s->purchase->cost_extra ?? 0), 2);

        $rows = $sales->map(fn ($s) => [
            ...SaleResource::make($s)->resolve(),
            'cost' => $cost($s),
            'profit' => $s->status === 'active' ? round($s->sale_price - $cost($s), 2) : 0,
        ]);

        $active = $sales->where('status', 'active');

        return $this->success([
            'totals' => [
                'count' => $active->count(),
                'cancelled' => $sales->where('status', 'cancelled')->count(),
                'sales_amount' => round($active->sum('sale_price'), 2),
                'paid' => round($active->sum('total_paid'), 2),
                'due' => round($active->sum('due_amount'), 2),
                'cost' => round($active->sum($cost), 2),
                'profit' => round($active->sum(fn ($s) => $s->sale_price - $cost($s)), 2),
            ],
            'by_type' => $active->groupBy(fn ($s) => $s->vehicle->vehicle_type)->map(fn ($g, $type) => [
                'type' => $type, 'count' => $g->count(), 'amount' => round($g->sum('sale_price'), 2),
                'profit' => round($g->sum(fn ($s) => $s->sale_price - $cost($s)), 2),
            ])->values(),
            'sales' => $rows->values(),
        ]);
    }

    /**
     * A customer's or supplier's statement: what was charged (sales to a customer, purchases from a supplier),
     * what was paid, and the running balance (owed to us by a customer, owed by us to a supplier).
     */
    public function partyTransactions(Request $request): JsonResponse
    {
        $request->validate([
            'side' => ['required', 'in:customer,supplier'],
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $party = Party::findOrFail($request->party_id);
        $entries = $request->side === 'customer' ? $this->customerEntries($party->id) : $this->supplierEntries($party->id);

        $sorted = collect($entries)->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']])->values();
        $from = $request->from;
        $to = $request->to;

        $opening = $from ? round($sorted->filter(fn ($e) => $e['date'] < $from)->sum(fn ($e) => $e['charge'] - $e['paid']), 2) : 0.0;
        $inRange = $sorted->filter(fn ($e) => (! $from || $e['date'] >= $from) && (! $to || $e['date'] <= $to))->values();

        $balance = $opening;
        $rows = $inRange->map(function ($e) use (&$balance) {
            $balance = round($balance + $e['charge'] - $e['paid'], 2);

            return ['date' => $e['date'], 'kind' => $e['kind'], 'description' => $e['description'], 'vehicle' => $e['vehicle'], 'reference' => $e['reference'], 'charge' => $e['charge'], 'paid' => $e['paid'], 'balance' => $balance];
        });

        return $this->success([
            'party' => $party->only(['id', 'name', 'phone', 'alt_phone', 'address']),
            'side' => $request->side,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'rows' => $rows,
            'total_charge' => round($rows->sum('charge'), 2),
            'total_paid' => round($rows->sum('paid'), 2),
            'closing_balance' => $balance,
        ]);
    }

    /** @param array<int,array{0:string,1:?string}> $details label/value pairs shown on the transaction's voucher */
    private function entry(int $party_id, string $date, int $order, int $id, string $kind, string $description, ?string $vehicle, float $charge, float $paid, ?string $reference = null, string $no = '', array $details = []): array
    {
        return compact('party_id', 'date', 'order', 'id', 'kind', 'description', 'vehicle', 'charge', 'paid', 'reference', 'no', 'details');
    }

    private function customerEntries(?int $partyId = null): array
    {
        $out = [];
        $sales = Sale::with(['vehicle:id,chassis_no,brand,model,engine_no', 'payments.receiver:id,name', 'payments.installment:id,seq_no'])->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get();

        foreach ($sales as $sale) {
            $vehicle = $sale->vehicle->chassis_no;
            $name = trim("{$sale->vehicle->brand} {$sale->vehicle->model}");
            $total = $sale->grandTotal();
            $out[] = $this->entry($sale->party_id, $sale->sale_date->toDateString(), 0, $sale->id, 'sale', ucfirst($sale->payment_type).' sale', $vehicle, $total, 0, null, "SALE-{$sale->id}", [
                ['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Engine', $sale->vehicle->engine_no], ['Payment type', ucfirst($sale->payment_type)],
                ['Sale price', number_format($sale->sale_price, 2)], ['Down payment', number_format($sale->down_payment, 2)],
            ]);

            foreach ($sale->payments as $pay) {
                $label = $pay->amount < 0 ? 'Refund' : ($pay->method === 'adjustment' ? 'Adjusted against purchase' : 'Payment received ('.$pay->method.')');
                // a refund gives money back, so it adds to what they owe again
                $out[] = $this->entry($sale->party_id, $pay->paid_at->toDateString(), 1, $pay->id, 'payment', $label, $vehicle, $pay->amount < 0 ? abs($pay->amount) : 0, $pay->amount > 0 ? $pay->amount : 0, $pay->reference, 'RCPT-'.$pay->id, [
                    ['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Sale', "#{$sale->id}"], ['Method', ucfirst($pay->method)],
                    ['Installment', $pay->installment ? "#{$pay->installment->seq_no}" : null], ['Received by', $pay->receiver?->name], ['Note', $pay->note],
                ]);
            }

            if ($sale->status === 'cancelled') {
                $net = round($sale->payments->sum('amount'), 2);
                $out[] = $this->entry($sale->party_id, ($sale->cancelled_at ?? now())->toDateString(), 2, $sale->id, 'cancel', 'Sale cancelled', $vehicle, 0, round($total - $net, 2), null, "CXL-{$sale->id}", [['Chassis', $vehicle], ['Reason', $sale->cancel_reason]]);
            }
        }

        return $out;
    }

    private function supplierEntries(?int $partyId = null): array
    {
        $out = [];
        $purchases = Purchase::with(['vehicle:id,chassis_no,brand,model,engine_no', 'payments'])->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get();

        foreach ($purchases as $p) {
            $vehicle = $p->vehicle->chassis_no;
            $name = trim("{$p->vehicle->brand} {$p->vehicle->model}");
            $car = [['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Engine', $p->vehicle->engine_no]];
            $out[] = $this->entry($p->party_id, $p->purchase_date->toDateString(), 0, $p->id, 'purchase', ($p->source === 'customer' ? 'Bought back' : 'Purchase').($p->memo_no ? " · memo {$p->memo_no}" : ''), $vehicle, (float) $p->price, 0, null, "PUR-{$p->id}", [...$car, ['Memo no', $p->memo_no], ['Price', number_format($p->price, 2)]]);

            // paid when the purchase was recorded = everything paid minus the later payments
            $initial = round($p->paid_amount - $p->payments->sum('amount'), 2);
            if ($initial > 0) {
                $out[] = $this->entry($p->party_id, $p->purchase_date->toDateString(), 1, $p->id, 'payment', 'Paid at purchase'.($p->payment_method ? " ({$p->payment_method})" : ''), $vehicle, 0, $initial, $p->payment_reference, "PAY-P{$p->id}", [...$car, ['Method', $p->payment_method ? ucfirst($p->payment_method) : null], ['Purchase', "#{$p->id}"]]);
            }

            if ($p->due_adjustment > 0) {
                $out[] = $this->entry($p->party_id, $p->purchase_date->toDateString(), 1, $p->id, 'payment', 'Adjusted against their dues', $vehicle, 0, (float) $p->due_adjustment, null, "ADJ-{$p->id}", [...$car, ['Purchase', "#{$p->id}"]]);
            }

            foreach ($p->payments as $pay) {
                // money taken from an advance was counted when the advance was given
                $fromAdvance = $pay->method === 'advance';
                $out[] = $this->entry($p->party_id, $pay->paid_at->toDateString(), 1, $pay->id, 'payment', $fromAdvance ? 'Paid from advance' : "Payment ({$pay->method})", $vehicle, 0, $fromAdvance ? 0 : (float) $pay->amount, $pay->reference, 'PAY-'.$pay->id, [...$car, ['Purchase', "#{$p->id}"], ['Method', ucfirst($pay->method)], ['Note', $pay->note]]);
            }
        }

        foreach (Advance::query()->when($partyId, fn ($q) => $q->where('party_id', $partyId))->get() as $a) {
            $out[] = $this->entry($a->party_id, $a->paid_at->toDateString(), 1, $a->id, 'advance', "Advance paid ({$a->method})", null, 0, (float) $a->amount, $a->reference, "ADV-{$a->id}", [['Method', ucfirst($a->method)], ['Note', $a->note]]);
        }

        return $out;
    }

    /** Every customer's (or supplier's) totals in a period, one line each: the list behind the statements. */
    public function partySummary(Request $request): JsonResponse
    {
        $request->validate(['side' => ['required', 'in:customer,supplier'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $entries = collect($request->side === 'customer' ? $this->customerEntries() : $this->supplierEntries());
        $from = $request->from;
        $to = $request->to;
        $parties = Party::whereIn('id', $entries->pluck('party_id')->unique())->get(['id', 'name', 'phone', 'alt_phone'])->keyBy('id');

        $rows = $entries->groupBy('party_id')->map(function ($list, $partyId) use ($from, $to, $parties) {
            $opening = $from ? round($list->filter(fn ($e) => $e['date'] < $from)->sum(fn ($e) => $e['charge'] - $e['paid']), 2) : 0.0;
            $range = $list->filter(fn ($e) => (! $from || $e['date'] >= $from) && (! $to || $e['date'] <= $to));
            $charge = round($range->sum('charge'), 2);
            $paid = round($range->sum('paid'), 2);
            $last = $range->max('date');

            return [
                'party' => $parties[$partyId]->only(['id', 'name', 'phone', 'alt_phone']),
                'opening_balance' => $opening, 'charge' => $charge, 'paid' => $paid,
                'closing_balance' => round($opening + $charge - $paid, 2), 'transactions' => $range->count(), 'last_date' => $last,
            ];
        })->filter(fn ($r) => $r['transactions'] > 0 || $r['opening_balance'] != 0)->sortByDesc('closing_balance')->values();

        return $this->success([
            'side' => $request->side, 'from' => $from, 'to' => $to, 'rows' => $rows,
            'total_opening' => round($rows->sum('opening_balance'), 2), 'total_charge' => round($rows->sum('charge'), 2),
            'total_paid' => round($rows->sum('paid'), 2), 'total_closing' => round($rows->sum('closing_balance'), 2),
        ]);
    }

    /** One day's business: what was sold and bought, due collected from customers, and paid to suppliers, with the cash that moved. */
    public function daily(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->date ?: today()->toDateString();
        $name = fn ($v) => trim(($v->brand ?? '').' '.($v->model ?? ''));

        $sales = Sale::with(['vehicle:id,chassis_no,brand,model', 'party:id,name,phone', 'plan', 'payments'])->whereDate('sale_date', $date)->where('status', 'active')->orderBy('id')->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id, 'vehicle' => $s->vehicle->chassis_no, 'vehicle_name' => $name($s->vehicle), 'party' => $s->party->name, 'phone' => $s->party->phone,
                'payment_type' => $s->payment_type, 'total' => $s->grandTotal(), 'received' => round((float) $s->down_payment, 2), 'due' => round($s->grandTotal() - $s->down_payment, 2),
            ]);

        $purchases = Purchase::with(['vehicle:id,chassis_no,brand,model', 'party:id,name,phone', 'payments'])->whereDate('purchase_date', $date)->orderBy('id')->get()
            ->map(fn (Purchase $p) => [
                'id' => $p->id, 'vehicle' => $p->vehicle->chassis_no, 'vehicle_name' => $name($p->vehicle), 'party' => $p->party->name, 'phone' => $p->party->phone, 'memo_no' => $p->memo_no,
                'total' => (float) $p->price, 'paid' => round($p->paid_amount - $p->payments->sum('amount'), 2), 'due' => round($p->price - $p->paid_amount - $p->due_adjustment, 2),
            ]);

        $collections = Payment::with(['sale.party:id,name,phone', 'sale.vehicle:id,chassis_no'])->whereDate('paid_at', $date)
            ->where('method', '!=', 'adjustment')
            // money paid when the vehicle was sold is counted with the sale, not as a collection
            ->where(fn ($q) => $q->whereNull('note')->orWhereNotIn('note', ['Full payment at sale', 'Down payment']))
            ->orderBy('id')->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id, 'sale_id' => $p->sale_id, 'party' => $p->sale->party->name, 'phone' => $p->sale->party->phone, 'vehicle' => $p->sale->vehicle->chassis_no,
                'method' => $p->method, 'amount' => (float) $p->amount, 'note' => $p->note,
            ]);

        $supplierPayments = collect()
            ->concat(PurchasePayment::with(['purchase.party:id,name,phone', 'purchase.vehicle:id,chassis_no'])->whereDate('paid_at', $date)->where('method', '!=', 'advance')->orderBy('id')->get()
                ->map(fn ($p) => ['party' => $p->purchase->party->name, 'phone' => $p->purchase->party->phone, 'vehicle' => $p->purchase->vehicle->chassis_no, 'kind' => 'Due paid', 'method' => $p->method, 'amount' => (float) $p->amount, 'reference' => $p->reference]))
            ->concat(Advance::with('party:id,name,phone')->whereDate('paid_at', $date)->orderBy('id')->get()
                ->map(fn ($a) => ['party' => $a->party->name, 'phone' => $a->party->phone, 'vehicle' => null, 'kind' => 'Advance paid', 'method' => $a->method, 'amount' => (float) $a->amount, 'reference' => $a->reference]))
            ->values();

        // entered by hand (add investment / pay installment); the entries a sale or collection makes by itself are not cash the shop moved
        $investorRows = InvestorSettlement::with('investor:id,name,phone')->whereDate('date', $date)
            ->where(fn ($q) => $q->whereNull('note')->orWhere('note', 'not like', 'Auto:%'))->orderBy('id')->get()
            ->map(fn (InvestorSettlement $i) => [
                'id' => $i->id, 'investor' => $i->investor->name, 'phone' => $i->investor->phone, 'type' => $i->type, 'amount' => (float) $i->amount,
                'direction' => $i->type === 'investment' ? 'in' : 'out', 'note' => $i->note,
            ]);
        $investedIn = round($investorRows->where('direction', 'in')->sum('amount'), 2);
        $paidInvestors = round($investorRows->where('direction', 'out')->sum('amount'), 2);

        $receivedAtSale = round($sales->sum('received'), 2);
        $collected = round($collections->sum('amount'), 2);
        $paidAtPurchase = round($purchases->sum('paid'), 2);
        $paidSuppliers = round($supplierPayments->sum('amount'), 2);

        return $this->success([
            'date' => $date,
            'sales' => ['rows' => $sales, 'count' => $sales->count(), 'total' => round($sales->sum('total'), 2), 'received' => $receivedAtSale, 'due' => round($sales->sum('due'), 2)],
            'purchases' => ['rows' => $purchases, 'count' => $purchases->count(), 'total' => round($purchases->sum('total'), 2), 'paid' => $paidAtPurchase, 'due' => round($purchases->sum('due'), 2)],
            'collections' => ['rows' => $collections, 'count' => $collections->count(), 'total' => $collected],
            'supplier_payments' => ['rows' => $supplierPayments, 'count' => $supplierPayments->count(), 'total' => $paidSuppliers],
            'investor_received' => ['rows' => $investorRows->where('direction', 'in')->values(), 'count' => $investorRows->where('direction', 'in')->count(), 'total' => $investedIn],
            'investor_paid' => ['rows' => $investorRows->where('direction', 'out')->values(), 'count' => $investorRows->where('direction', 'out')->count(), 'total' => $paidInvestors],
            'cash' => [
                'in' => round($receivedAtSale + $collected + $investedIn, 2),
                'out' => round($paidAtPurchase + $paidSuppliers + $paidInvestors, 2),
                'net' => round($receivedAtSale + $collected + $investedIn - $paidAtPurchase - $paidSuppliers - $paidInvestors, 2),
            ],
        ]);
    }

    /** Every customer (or supplier) transaction in a period, newest day last: the "per transaction" report. */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate(['side' => ['required', 'in:customer,supplier'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $from = $request->from;
        $to = $request->to;
        $entries = collect($request->side === 'customer' ? $this->customerEntries() : $this->supplierEntries())
            ->filter(fn ($e) => (! $from || $e['date'] >= $from) && (! $to || $e['date'] <= $to))
            ->sortBy([['date', 'asc'], ['party_id', 'asc'], ['order', 'asc'], ['id', 'asc']])->values();
        $parties = Party::whereIn('id', $entries->pluck('party_id')->unique())->get(['id', 'name', 'phone'])->keyBy('id');

        $rows = $entries->map(fn ($e) => [
            'date' => $e['date'], 'party_id' => $e['party_id'], 'party' => $parties[$e['party_id']]->name ?? '-', 'phone' => $parties[$e['party_id']]->phone ?? null, 'kind' => $e['kind'],
            'description' => $e['description'], 'vehicle' => $e['vehicle'], 'reference' => $e['reference'], 'charge' => $e['charge'], 'paid' => $e['paid'],
            'no' => $e['no'], 'details' => array_values(array_filter($e['details'], fn ($d) => $d[1] !== null && $d[1] !== '')),
        ]);

        return $this->success([
            'side' => $request->side, 'from' => $from, 'to' => $to, 'rows' => $rows,
            'total_charge' => round($rows->sum('charge'), 2), 'total_paid' => round($rows->sum('paid'), 2),
        ]);
    }

    /** Every settlement with an investor (money in, principal returned, profit paid, withdrawals) in a period. */
    public function investorTransactions(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'investor_id' => ['nullable', 'integer'], 'type' => ['nullable', 'in:investment,principal_return,profit_share,withdrawal'],
        ]);

        $rows = InvestorSettlement::with('investor:id,name,phone')
            ->when($request->from, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when($request->investor_id, fn ($q, $v) => $q->where('investor_id', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(fn (InvestorSettlement $i) => [
                'id' => $i->id, 'date' => $i->date->toDateString(), 'investor_id' => $i->investor_id, 'investor' => $i->investor->name, 'phone' => $i->investor->phone,
                'type' => $i->type, 'amount' => (float) $i->amount, 'note' => $i->note, 'auto' => str_starts_with((string) $i->note, 'Auto:'),
            ]);

        $sum = fn (string $type) => round($rows->where('type', $type)->sum('amount'), 2);

        return $this->success([
            'rows' => $rows,
            'totals' => ['investment' => $sum('investment'), 'principal_return' => $sum('principal_return'), 'profit_share' => $sum('profit_share'), 'withdrawal' => $sum('withdrawal')],
        ]);
    }
}
