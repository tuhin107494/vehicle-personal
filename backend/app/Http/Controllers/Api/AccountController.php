<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Vehicle;
use App\Services\PayableService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accounts payable (suppliers) and receivable (customers) in one place: see what a party is owed or owes,
 * then record one payment that is spread over their open items, oldest first.
 */
class AccountController extends Controller
{
    public function __construct(private PayableService $payables, private PaymentService $payments) {}

    public function summary(AccountRequest $request): JsonResponse
    {
        $this->authorizeSide($request);

        return $this->success($this->summaryFor($request->input('side'), $this->person($request)));
    }

    public function store(AccountRequest $request): JsonResponse
    {
        $this->authorizeSide($request);
        $side = $request->input('side');
        $party = $this->person($request);
        $amount = Money::paisa($request->input('amount')); // all of this is worked out in paisa
        $data = $request->safe()->only(array_merge(['method', 'paid_at', 'reference', 'note'], $side === 'customer' ? ['memo_no'] : []));

        $data['batch_no'] = (string) Str::uuid(); // everything this payment touches is one invoice, whichever sales or installments it is spread over
        $vouchers = []; // numbers of the ledger entries this payment created, as the reports print them

        DB::transaction(function () use ($side, $party, $amount, $data, $request, &$vouchers) {
            $left = $amount;

            if ($side === 'customer') {
                $sales = Sale::where('customer_id', $party->id)->where('status', 'active')->where('due_amount', '>', 0)
                    ->when($request->filled('sale_id'), fn ($q) => $q->where('id', $request->input('sale_id')))
                    ->orderBy('sale_date')->orderBy('id')->get();

                $owed = Money::paisa(Money::sum($sales, 'due_amount'));

                if ($amount > $owed) {
                    throw new BusinessRuleException(($request->filled('sale_id') ? 'This vehicle has' : 'This customer owes').' '.Money::format($owed).' due, which is less than '.Money::format($amount).'.');
                }

                foreach ($sales as $sale) {
                    if ($left <= 0) {
                        break;
                    }

                    $take = min($left, Money::paisa($sale->due_amount));
                    $created = $this->payments->collect($sale, ['amount' => Money::taka($take)] + $data, $request->user());
                    $vouchers[] = 'CP-'.collect($created)->min('id'); // one invoice per sale: numbered by its first row
                    $left -= $take;
                }

                return;
            }

            $purchases = Purchase::where('supplier_id', $party->id)->whereRaw('price - paid_amount - due_adjustment > 0')->orderBy('purchase_date')->orderBy('id')->get();

            foreach ($purchases as $purchase) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, Money::paisa($this->payables->balance($purchase)));
                $vouchers[] = 'SP-'.$this->payables->pay($purchase, ['amount' => Money::taka($take)] + $data, $request->user())->id;
                $left -= $take;
            }

            if ($left > 0) {
                // nothing (more) is owed to them, so the rest is paid ahead of the next purchase
                $vouchers[] = 'SP-'.$this->payables->addAdvance(['supplier_id' => $party->id, 'amount' => Money::taka($left), 'method' => $data['method']] + $data, $request->user())->id;
            }
        });

        return $this->success(
            $this->summaryFor($side, $party) + ['vouchers' => $vouchers],
            $side === 'customer' ? 'Payment received' : 'Payment recorded',
            201,
        );
    }

    private function authorizeSide(AccountRequest $request): void
    {
        $key = $request->input('side') === 'customer' ? 'payments.collect' : 'purchases.edit';
        abort_unless($request->user()->hasPermission($key), 403, 'You do not have permission to do this.');
    }

    /** The customer being collected from, or the supplier being paid. */
    private function person(AccountRequest $request): Customer|Supplier
    {
        return $request->input('side') === 'customer'
            ? Customer::findOrFail($request->input('customer_id'))
            : Supplier::findOrFail($request->input('supplier_id'));
    }

    private function summaryFor(string $side, Customer|Supplier $party): array
    {
        $party = $party->only(['id', 'name', 'phone', 'alt_phone', 'bank_name', 'bank_branch', 'bank_account_name', 'bank_account_no', 'bank_info']);

        if ($side === 'customer') {
            $items = Sale::with(['vehicle:id,chassis_no,engine_no,brand,model,year', 'purchase:id,memo_no', 'plan.installments', 'promises'])->where('customer_id', $party['id'])->where('status', 'active')->where('due_amount', '>', 0)
                ->orderBy('sale_date')->orderBy('id')->get()
                ->map(fn (Sale $s) => [
                    'kind' => 'sale', 'id' => $s->id, 'date' => $s->sale_date->toDateString(),
                    'vehicle' => $s->vehicle->chassis_no, 'vehicle_name' => $this->vehicleName($s->vehicle), 'engine_no' => $s->vehicle->engine_no, 'memo_no' => $s->purchase?->memo_no,
                    'due' => $s->due_amount, 'payment_type' => $s->payment_type,
                ] + $this->lateness($s));

            return [
                'person' => $party, 'total_due' => Money::sum($items, 'due'), 'current_due' => Money::sum($items, 'current_due'),
                'days_late' => (int) $items->max('days_late'), 'advance_available' => 0, 'items' => $items->values(),
            ];
        }

        $items = Purchase::with('vehicle:id,chassis_no,engine_no,brand,model,year')->where('supplier_id', $party['id'])->whereRaw('price - paid_amount - due_adjustment > 0')
            ->orderBy('purchase_date')->orderBy('id')->get()
            ->map(fn (Purchase $p) => ['kind' => 'purchase', 'id' => $p->id, 'date' => $p->purchase_date->toDateString(), 'vehicle' => $p->vehicle->chassis_no, 'vehicle_name' => $this->vehicleName($p->vehicle), 'engine_no' => $p->vehicle->engine_no, 'memo_no' => $p->memo_no, 'due' => $this->payables->balance($p)]);

        return [
            'person' => $party,
            'total_due' => Money::sum($items, 'due'),
            'advance_available' => $this->payables->advanceBalance($party['id']),
            'items' => $items->values(),
        ];
    }

    /** What is payable now (dates reached), how many days the oldest of it is late, and the next date still ahead. */
    private function lateness(Sale $sale): array
    {
        $dates = $sale->payment_type === 'installment' && $sale->plan
            ? $sale->plan->installments->whereIn('status', ['pending', 'partial', 'overdue'])->map(fn ($i) => [$i->due_date, $i->remaining()])
            : $sale->promises->whereIn('status', ['open', 'broken'])->map(fn ($p) => [$p->promised_date, min($p->promised_amount, $sale->due_amount)]);

        // a due with no promised date has nothing to wait for, so all of it is payable now
        if ($dates->isEmpty()) {
            return ['current_due' => $sale->due_amount, 'days_late' => 0, 'next_date' => null];
        }

        $now = $dates->filter(fn ($d) => $d[0]->copy()->startOfDay()->lte(today()));
        $next = $dates->filter(fn ($d) => $d[0]->copy()->startOfDay()->gt(today()))->sortBy(fn ($d) => $d[0])->first();

        return [
            'current_due' => min(Money::sum($now, fn ($d) => $d[1]), $sale->due_amount),
            'days_late' => $now->isEmpty() ? 0 : (int) $now->min(fn ($d) => $d[0])->copy()->startOfDay()->diffInDays(today()),
            'next_date' => $next ? $next[0]->toDateString() : null,
        ];
    }

    private function vehicleName(Vehicle $v): string
    {
        return trim("{$v->brand} {$v->model} {$v->year}");
    }
}
