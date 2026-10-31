<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestorResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SaleResource;
use App\Http\Resources\VehicleResource;
use App\Models\Advance;
use App\Services\TransactionService;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\InvestorPortfolioService;
use App\Support\Money;
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
        $vehicles = Vehicle::with(['currentPurchase.supplier', 'currentPurchase.customer'])->where('status', 'in_stock')->limit(1000)->get(); // a safety cap, far above any real stock

        return $this->success([
            'total_count' => $vehicles->count(),
            'total_cost' => Money::sum($vehicles, fn ($v) => Money::add($v->currentPurchase->price ?? 0, $v->currentPurchase->cost_extra ?? 0)),
            'vehicles' => $vehicles->map(fn ($v) => [
                ...VehicleResource::make($v)->resolve(),
                'days_in_stock' => $v->currentPurchase ? (int) $v->currentPurchase->purchase_date->diffInDays(today()) : null,
            ]),
        ]);
    }

    public function dues(): JsonResponse
    {
        $sales = Sale::with(['vehicle', 'customer', 'plan.installments', 'promises'])
            ->where('status', 'active')->where('due_amount', '>', 0)->orderByDesc('due_amount')->limit(1000)->get(); // a safety cap

        return $this->success([
            'total_due' => Money::sum($sales, 'due_amount'),
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
        $investors = Investor::orderBy('name')->get();
        $summaries = $portfolio->summaries($investors);

        return $this->success($investors->map(fn ($i) => [
            'investor' => InvestorResource::make($i)->resolve(),
            'summary' => $summaries[$i->id],
        ]));
    }

    /** Purchases in a date range: totals, a breakdown by vehicle type and the list itself. */
    public function purchases(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $purchases = Purchase::with(['vehicle', 'supplier', 'customer'])
            ->when($request->from, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->when($request->vehicle_type, fn ($q, $t) => $q->whereHas('vehicle', fn ($q) => $q->where('vehicle_type', $t)))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->source, fn ($q, $src) => $q->where('source', $src))
            ->orderByDesc('purchase_date')->orderByDesc('id')->get();

        $rows = $purchases->map(fn ($p) => [
            ...PurchaseResource::make($p)->resolve(),
            'total_cost' => Money::add($p->price, $p->cost_extra),
            'unpaid' => max(Money::add($p->price, -$p->paid_amount), 0),
        ]);

        return $this->success([
            'totals' => [
                'count' => $purchases->count(),
                'price' => Money::sum($purchases, 'price'),
                'extra_cost' => Money::sum($purchases, 'cost_extra'),
                'total_cost' => Money::sum($rows, 'total_cost'),
                'paid' => Money::sum($purchases, 'paid_amount'),
                'unpaid' => Money::sum($rows, 'unpaid'),
            ],
            'by_type' => $purchases->groupBy(fn ($p) => $p->vehicle->vehicle_type)->map(fn ($g, $type) => [
                'type' => $type, 'count' => $g->count(), 'amount' => Money::sum($g, fn ($p) => Money::add($p->price, $p->cost_extra)),
            ])->values(),
            'purchases' => $rows->values(),
        ]);
    }

    /** Sales in a date range: totals (cancelled sales are listed but not counted), profit, a breakdown by type and the list. */
    public function sales(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $sales = Sale::with(['vehicle', 'customer', 'purchase', 'plan'])
            ->when($request->from, fn ($q, $v) => $q->whereDate('sale_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('sale_date', '<=', $v))
            ->when($request->vehicle_type, fn ($q, $t) => $q->whereHas('vehicle', fn ($q) => $q->where('vehicle_type', $t)))
            ->when($request->payment_type, fn ($q, $t) => $q->where('payment_type', $t))
            ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->status, fn ($q, $st) => $q->where('status', $st))
            ->orderByDesc('sale_date')->orderByDesc('id')->get();

        $cost = fn ($s) => Money::add($s->purchase->price ?? 0, $s->purchase->cost_extra ?? 0);
        $profit = fn ($s) => Money::add($s->sale_price, $s->charges_total, -$cost($s)); // charges to the customer are extra income

        $rows = $sales->map(fn ($s) => [
            ...SaleResource::make($s)->resolve(),
            'cost' => $cost($s),
            'profit' => $s->status === 'active' ? $profit($s) : 0,
        ]);

        $active = $sales->where('status', 'active');

        return $this->success([
            'totals' => [
                'count' => $active->count(),
                'cancelled' => $sales->where('status', 'cancelled')->count(),
                'sales_amount' => Money::sum($active, 'sale_price'),
                'paid' => Money::sum($active, 'total_paid'),
                'due' => Money::sum($active, 'due_amount'),
                'cost' => Money::sum($active, $cost),
                'profit' => Money::sum($active, $profit),
            ],
            'by_type' => $active->groupBy(fn ($s) => $s->vehicle->vehicle_type)->map(fn ($g, $type) => [
                'type' => $type, 'count' => $g->count(), 'amount' => Money::sum($g, 'sale_price'),
                'profit' => Money::sum($g, $profit),
            ])->values(),
            'sales' => $rows->values(),
        ]);
    }

    /**
     * A customer's or supplier's statement: what was charged (sales to a customer, purchases from a supplier),
     * what was paid, and the running balance (owed to us by a customer, owed by us to a supplier).
     */
    public function personTransactions(Request $request): JsonResponse
    {
        $request->validate([
            'side' => ['required', 'in:customer,supplier'],
            'customer_id' => ['required_if:side,customer', 'nullable', 'integer', 'exists:customers,id'],
            'supplier_id' => ['required_if:side,supplier', 'nullable', 'integer', 'exists:suppliers,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $party = $request->side === 'customer' ? Customer::findOrFail($request->customer_id) : Supplier::findOrFail($request->supplier_id);
        $entries = $request->side === 'customer' ? $this->customerEntries($party->id) : $this->supplierEntries($party->id);

        $sorted = collect($entries)->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']])->values();
        $from = $request->from;
        $to = $request->to;

        $opening = $from ? $this->net($sorted->filter(fn ($e) => $e['date'] < $from)) : 0.0;
        $inRange = $sorted->filter(fn ($e) => (! $from || $e['date'] >= $from) && (! $to || $e['date'] <= $to))->values();

        $balance = $opening;
        // the balance runs oldest to newest, then the list is turned around so the latest shows first
        $rows = $inRange->map(function ($e) use (&$balance) {
            $balance = Money::add($balance, $e['charge'], -$e['paid']);

            return ['date' => $e['date'], 'kind' => $e['kind'], 'description' => $e['description'], 'vehicle' => $e['vehicle'], 'reference' => $e['reference'], 'charge' => $e['charge'], 'paid' => $e['paid'], 'balance' => $balance, 'no' => $e['no'], 'tx' => $e['tx'], 'details' => array_values(array_filter($e['details'], fn ($d) => $d[1] !== null && $d[1] !== ''))];
        })->reverse()->values();

        return $this->success([
            'person' => $party->only(['id', 'name', 'phone', 'alt_phone', 'address']),
            'side' => $request->side,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'rows' => $rows,
            'total_charge' => Money::sum($rows, 'charge'),
            'total_paid' => Money::sum($rows, 'paid'),
            'closing_balance' => $balance,
        ]);
    }

    /** What a run of statement entries adds to the balance: charges less payments. */
    private function net(iterable $entries): float
    {
        return Money::sum($entries, fn ($e) => Money::add($e['charge'], -$e['paid']));
    }

    /** @param array<int,array{0:string,1:?string}> $details label/value pairs shown on the transaction's voucher */
    private function entry(int $person_id, string $date, int $order, int $id, string $kind, string $description, ?string $vehicle, float $charge, float $paid, ?string $reference = null, string $no = '', array $details = [], ?array $tx = null): array
    {
        return compact('person_id', 'date', 'order', 'id', 'kind', 'description', 'vehicle', 'charge', 'paid', 'reference', 'no', 'details', 'tx');
    }

    private function customerEntries(?int $customerId = null): array
    {
        $out = [];
        $sales = Sale::with(['vehicle:id,chassis_no,brand,model,engine_no', 'plan', 'charges', 'payments.receiver:id,name', 'payments.installment:id,seq_no'])->when($customerId, fn ($q) => $q->where('customer_id', $customerId))->get();

        foreach ($sales as $sale) {
            $vehicle = $sale->vehicle->chassis_no;
            $name = trim("{$sale->vehicle->brand} {$sale->vehicle->model}");
            $total = $sale->grandTotal();
            $out[] = $this->entry($sale->customer_id, $sale->sale_date->toDateString(), 0, $sale->id, 'sale', ucfirst($sale->payment_type).' sale', $vehicle, $total, 0, null, "SALE-{$sale->id}", [
                ['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Engine', $sale->vehicle->engine_no], ['Payment type', ucfirst($sale->payment_type)],
                ['Sale price', number_format($sale->sale_price, 2)], ['Down payment', number_format($sale->down_payment, 2)],
            ]);

            foreach ($sale->payments as $pay) {
                $label = $pay->amount < 0 ? 'Refund' : ($pay->method === 'adjustment' ? 'Adjusted against purchase' : 'Payment received ('.$pay->method.')');
                // a refund gives money back, so it adds to what they owe again
                $out[] = $this->entry($sale->customer_id, $pay->paid_at->toDateString(), 1, $pay->id, 'payment', $label, $vehicle, $pay->amount < 0 ? abs($pay->amount) : 0, $pay->amount > 0 ? $pay->amount : 0, $pay->reference, 'CP-'.$pay->id, [
                    ['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Sale', "#{$sale->id}"], ['Method', ucfirst($pay->method)],
                    ['Installment', $pay->installment ? "#{$pay->installment->seq_no}" : null], ['Received by', $pay->receiver?->name], ['Memo no.', $pay->memo_no], ['Note', $pay->note],
                ], TransactionService::tx($pay));
            }

            foreach ($sale->charges as $charge) {
                $out[] = $this->entry($sale->customer_id, $charge->charged_on->toDateString(), 0, $charge->id, 'charge', $charge->purpose, $vehicle, (float) $charge->amount, 0, null, "CHG-{$charge->id}", [
                    ['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Sale', "#{$sale->id}"], ['Note', $charge->note],
                ]);
            }

            if ($sale->status === 'cancelled') {
                $out[] = $this->entry($sale->customer_id, ($sale->cancelled_at ?? now())->toDateString(), 2, $sale->id, 'cancel', 'Sale cancelled', $vehicle, 0, Money::add($total, -Money::sum($sale->payments, 'amount')), null, "CXL-{$sale->id}", [['Chassis', $vehicle], ['Reason', $sale->cancel_reason]]);
            }
        }

        return $out;
    }

    private function supplierEntries(?int $supplierId = null): array
    {
        $out = [];
        $purchases = Purchase::with(['vehicle:id,chassis_no,brand,model,engine_no', 'payments'])->whereNotNull('supplier_id')->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get();

        foreach ($purchases as $p) {
            $vehicle = $p->vehicle->chassis_no;
            $name = trim("{$p->vehicle->brand} {$p->vehicle->model}");
            $car = [['Vehicle', $name ?: null], ['Chassis', $vehicle], ['Engine', $p->vehicle->engine_no]];
            $out[] = $this->entry($p->supplier_id, $p->purchase_date->toDateString(), 0, $p->id, 'purchase', ($p->source === 'customer' ? 'Bought back' : 'Purchase').($p->memo_no ? " · memo {$p->memo_no}" : ''), $vehicle, (float) $p->price, 0, null, "PUR-{$p->id}", [...$car, ['Memo no', $p->memo_no], ['Price', number_format($p->price, 2)]]);

            // paid when the purchase was recorded = everything paid minus the later payments
            $initial = Money::add($p->paid_amount, -Money::sum($p->payments, 'amount'));
            if ($initial > 0) {
                $out[] = $this->entry($p->supplier_id, $p->purchase_date->toDateString(), 1, $p->id, 'payment', 'Paid at purchase'.($p->payment_method ? " ({$p->payment_method})" : ''), $vehicle, 0, $initial, $p->payment_reference, "PAY-P{$p->id}", [...$car, ['Method', $p->payment_method ? ucfirst($p->payment_method) : null], ['Purchase', "#{$p->id}"]]);
            }

            if ($p->due_adjustment > 0) {
                $out[] = $this->entry($p->supplier_id, $p->purchase_date->toDateString(), 1, $p->id, 'payment', 'Adjusted against their dues', $vehicle, 0, (float) $p->due_adjustment, null, "ADJ-{$p->id}", [...$car, ['Purchase', "#{$p->id}"]]);
            }

            foreach ($p->payments as $pay) {
                // money taken from an advance was counted when the advance was given
                $fromAdvance = $pay->method === 'advance';
                $out[] = $this->entry($p->supplier_id, $pay->paid_at->toDateString(), 1, $pay->id, 'payment', $fromAdvance ? 'Paid from advance' : "Payment ({$pay->method})", $vehicle, 0, $fromAdvance ? 0 : (float) $pay->amount, $pay->reference, 'SP-'.$pay->id, [...$car, ['Purchase', "#{$p->id}"], ['Method', ucfirst($pay->method)], ['Note', $pay->note]], TransactionService::tx($pay));
            }
        }

        foreach (Advance::query()->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get() as $a) {
            $out[] = $this->entry($a->supplier_id, $a->paid_at->toDateString(), 1, $a->id, 'advance', "Advance paid ({$a->method})", null, 0, (float) $a->amount, $a->reference, "SP-{$a->id}", [['Method', ucfirst($a->method)], ['Note', $a->note]], TransactionService::tx($a));
        }

        return $out;
    }

    /** Every customer's (or supplier's) totals in a period, one line each: the list behind the statements. */
    public function personSummary(Request $request): JsonResponse
    {
        $request->validate(['side' => ['required', 'in:customer,supplier'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $entries = collect($request->side === 'customer' ? $this->customerEntries() : $this->supplierEntries());
        $from = $request->from;
        $to = $request->to;
        $model = $request->side === 'customer' ? Customer::class : Supplier::class;
        $people = $model::whereIn('id', $entries->pluck('person_id')->unique())->get(['id', 'name', 'phone', 'alt_phone'])->keyBy('id');

        $rows = $entries->groupBy('person_id')->map(function ($list, $personId) use ($from, $to, $people) {
            $opening = $from ? $this->net($list->filter(fn ($e) => $e['date'] < $from)) : 0.0;
            $range = $list->filter(fn ($e) => (! $from || $e['date'] >= $from) && (! $to || $e['date'] <= $to));
            $charge = Money::sum($range, 'charge');
            $paid = Money::sum($range, 'paid');
            $last = $range->max('date');

            return [
                'person' => $people[$personId]->only(['id', 'name', 'phone', 'alt_phone']),
                'opening_balance' => $opening, 'charge' => $charge, 'paid' => $paid,
                'closing_balance' => Money::add($opening, $charge, -$paid), 'transactions' => $range->count(), 'last_date' => $last,
            ];
        })->filter(fn ($r) => $r['transactions'] > 0 || $r['opening_balance'] != 0)->sortByDesc('closing_balance')->values();

        return $this->success([
            'side' => $request->side, 'from' => $from, 'to' => $to, 'rows' => $rows,
            'total_opening' => Money::sum($rows, 'opening_balance'), 'total_charge' => Money::sum($rows, 'charge'),
            'total_paid' => Money::sum($rows, 'paid'), 'total_closing' => Money::sum($rows, 'closing_balance'),
        ]);
    }

    /** One day's business: what was sold and bought, due collected from customers, and paid to suppliers, with the cash that moved. */
    public function daily(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->date ?: today()->toDateString();
        $name = fn ($v) => trim(($v->brand ?? '').' '.($v->model ?? ''));

        $sales = Sale::with(['vehicle:id,chassis_no,brand,model', 'customer:id,name,phone', 'plan', 'payments'])->whereDate('sale_date', $date)->where('status', 'active')->orderBy('id')->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id, 'vehicle' => $s->vehicle->chassis_no, 'vehicle_name' => $name($s->vehicle), 'name' => $s->customer->name, 'phone' => $s->customer->phone,
                'payment_type' => $s->payment_type, 'total' => $s->grandTotal(), 'received' => Money::add($s->down_payment, -Money::sum($s->payments->where('note', 'Advance'), 'amount')), 'due' => Money::add($s->grandTotal(), -$s->down_payment),
            ]);

        // a sale made from a booking: its advance was already counted as cash on the day of the booking
        $fromBooking = Booking::whereIn('sale_id', $sales->pluck('id'))->pluck('advance_amount', 'sale_id');
        $sales = $sales->map(fn ($s) => $fromBooking->has($s['id']) ? [...$s, 'received' => Money::add($s['received'], -$fromBooking[$s['id']])] : $s);

        $bookingAdvances = Booking::with(['vehicle:id,chassis_no,brand,model', 'customer:id,name,phone'])->whereDate('booked_on', $date)->where('advance_amount', '>', 0)->orderBy('id')->get()
            ->map(fn (Booking $b) => ['id' => $b->id, 'name' => $b->customer->name, 'phone' => $b->customer->phone, 'vehicle' => $b->vehicle?->chassis_no, 'method' => $b->advance_method, 'amount' => (float) $b->advance_amount, 'status' => $b->status]);
        $bookingRefunds = Booking::with(['vehicle:id,chassis_no,brand,model', 'customer:id,name,phone'])->whereDate('closed_on', $date)->where('refund_amount', '>', 0)->orderBy('id')->get()
            ->map(fn (Booking $b) => ['id' => $b->id, 'name' => $b->customer->name, 'phone' => $b->customer->phone, 'vehicle' => $b->vehicle?->chassis_no, 'method' => $b->advance_method, 'amount' => (float) $b->refund_amount, 'status' => $b->status]);

        $purchases = Purchase::with(['vehicle:id,chassis_no,brand,model', 'supplier:id,name,phone', 'customer:id,name,phone', 'payments'])->whereDate('purchase_date', $date)->orderBy('id')->get()
            ->map(fn (Purchase $p) => [
                'id' => $p->id, 'vehicle' => $p->vehicle->chassis_no, 'vehicle_name' => $name($p->vehicle), 'name' => $p->seller()?->name, 'phone' => $p->seller()?->phone, 'memo_no' => $p->memo_no,
                'total' => (float) $p->price, 'paid' => Money::add($p->paid_amount, -Money::sum($p->payments, 'amount')), 'due' => Money::add($p->price, -$p->paid_amount, -$p->due_adjustment),
            ]);

        $collections = Payment::with(['sale.customer:id,name,phone', 'sale.vehicle:id,chassis_no'])->whereDate('paid_at', $date)
            ->where('method', '!=', 'adjustment')
            // money paid when the vehicle was sold is counted with the sale, not as a collection
            ->where(fn ($q) => $q->whereNull('note')->orWhereNotIn('note', ['Full payment at sale', 'Down payment']))
            ->orderBy('id')->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id, 'sale_id' => $p->sale_id, 'name' => $p->sale->customer->name, 'phone' => $p->sale->customer->phone, 'vehicle' => $p->sale->vehicle->chassis_no,
                'method' => $p->method, 'amount' => (float) $p->amount, 'note' => $p->note,
            ]);

        $supplierPayments = collect()
            ->concat(PurchasePayment::with(['purchase.supplier:id,name,phone', 'purchase.customer:id,name,phone', 'purchase.vehicle:id,chassis_no'])->whereDate('paid_at', $date)->where('method', '!=', 'advance')->orderBy('id')->get()
                ->map(fn (PurchasePayment $p) => ['name' => $p->purchase->seller()?->name, 'phone' => $p->purchase->seller()?->phone, 'vehicle' => $p->purchase->vehicle->chassis_no, 'kind' => 'Due paid', 'method' => $p->method, 'amount' => (float) $p->amount, 'reference' => $p->reference]))
            ->concat(Advance::with('supplier:id,name,phone')->whereDate('paid_at', $date)->orderBy('id')->get()
                ->map(fn ($a) => ['name' => $a->supplier->name, 'phone' => $a->supplier->phone, 'vehicle' => null, 'kind' => 'Advance paid', 'method' => $a->method, 'amount' => (float) $a->amount, 'reference' => $a->reference]))
            ->values();

        // entered by hand (add investment / pay installment); the entries a sale or collection makes by itself are not cash the shop moved
        $investorRows = InvestorSettlement::with('investor:id,name,phone')->whereDate('date', $date)
            ->where(fn ($q) => $q->whereNull('note')->orWhere('note', 'not like', 'Auto:%'))->orderBy('id')->get()
            ->map(fn (InvestorSettlement $i) => [
                'id' => $i->id, 'investor' => $i->investor->name, 'phone' => $i->investor->phone, 'type' => $i->type, 'amount' => (float) $i->amount,
                'direction' => $i->type === 'investment' ? 'in' : 'out', 'note' => $i->note,
            ]);
        $investedIn = Money::sum($investorRows->where('direction', 'in'), 'amount');
        $paidInvestors = Money::sum($investorRows->where('direction', 'out'), 'amount');

        $receivedAtSale = Money::sum($sales, 'received');
        $advancesTaken = Money::sum($bookingAdvances, 'amount');
        $advancesRefunded = Money::sum($bookingRefunds, 'amount');
        $collected = Money::sum($collections, 'amount');
        $paidAtPurchase = Money::sum($purchases, 'paid');
        $paidSuppliers = Money::sum($supplierPayments, 'amount');

        return $this->success([
            'date' => $date,
            'sales' => ['rows' => $sales, 'count' => $sales->count(), 'total' => Money::sum($sales, 'total'), 'received' => $receivedAtSale, 'due' => Money::sum($sales, 'due')],
            'purchases' => ['rows' => $purchases, 'count' => $purchases->count(), 'total' => Money::sum($purchases, 'total'), 'paid' => $paidAtPurchase, 'due' => Money::sum($purchases, 'due')],
            'collections' => ['rows' => $collections, 'count' => $collections->count(), 'total' => $collected],
            'booking_advances' => ['rows' => $bookingAdvances, 'count' => $bookingAdvances->count(), 'total' => $advancesTaken],
            'booking_refunds' => ['rows' => $bookingRefunds, 'count' => $bookingRefunds->count(), 'total' => $advancesRefunded],
            'supplier_payments' => ['rows' => $supplierPayments, 'count' => $supplierPayments->count(), 'total' => $paidSuppliers],
            'investor_received' => ['rows' => $investorRows->where('direction', 'in')->values(), 'count' => $investorRows->where('direction', 'in')->count(), 'total' => $investedIn],
            'investor_paid' => ['rows' => $investorRows->where('direction', 'out')->values(), 'count' => $investorRows->where('direction', 'out')->count(), 'total' => $paidInvestors],
            'cash' => [
                'in' => Money::add($receivedAtSale, $collected, $investedIn, $advancesTaken),
                'out' => Money::add($paidAtPurchase, $paidSuppliers, $paidInvestors, $advancesRefunded),
                'net' => Money::add($receivedAtSale, $collected, $investedIn, $advancesTaken, -$paidAtPurchase, -$paidSuppliers, -$paidInvestors, -$advancesRefunded),
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
            ->sortBy([['date', 'desc'], ['order', 'desc'], ['id', 'desc']])->values(); // newest first
        $model = $request->side === 'customer' ? Customer::class : Supplier::class;
        $people = $model::whereIn('id', $entries->pluck('person_id')->unique())->get(['id', 'name', 'phone'])->keyBy('id');

        $rows = $entries->map(fn ($e) => [
            'date' => $e['date'], 'person_id' => $e['person_id'], 'person' => $people[$e['person_id']]->name ?? '-', 'phone' => $people[$e['person_id']]->phone ?? null, 'kind' => $e['kind'],
            'description' => $e['description'], 'vehicle' => $e['vehicle'], 'reference' => $e['reference'], 'charge' => $e['charge'], 'paid' => $e['paid'],
            'no' => $e['no'], 'tx' => $e['tx'], 'details' => array_values(array_filter($e['details'], fn ($d) => $d[1] !== null && $d[1] !== '')),
        ]);

        return $this->success([
            'side' => $request->side, 'from' => $from, 'to' => $to, 'rows' => $rows,
            'total_charge' => Money::sum($rows, 'charge'), 'total_paid' => Money::sum($rows, 'paid'),
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

        $sum = fn (string $type) => Money::sum($rows->where('type', $type), 'amount');

        return $this->success([
            'rows' => $rows,
            'totals' => ['investment' => $sum('investment'), 'principal_return' => $sum('principal_return'), 'profit_share' => $sum('profit_share'), 'withdrawal' => $sum('withdrawal')],
        ]);
    }
}
