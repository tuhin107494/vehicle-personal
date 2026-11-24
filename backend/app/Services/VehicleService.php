<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Booking;
use App\Models\CallLog;
use App\Models\Draft;
use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\InstallmentPlan;
use App\Models\InvestorSale;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseEdit;
use App\Models\PurchasePayment;
use App\Models\RegistrationLog;
use App\Models\Sale;
use App\Models\SaleCharge;
use App\Models\SaleEdit;
use App\Models\Scopes\NotCancelledScope;
use App\Models\TransactionEdit;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDeletion;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleService
{
    /**
     * Removes a vehicle for good with everything recorded on it: its purchases and supplier payments, its sales with their
     * installments, promises, charges and customer payments, its investor sales with the investor's entries, and its bookings.
     * Meant for a vehicle entered by mistake (a second copy with a mistyped chassis number). The chassis, engine and
     * registration numbers are free to be entered again afterwards. A summary of what was removed is kept (VehicleDeletion).
     */
    public function delete(Vehicle $vehicle, ?User $user = null): void
    {
        DB::transaction(function () use ($vehicle, $user) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);

            $saleIds = Sale::where('vehicle_id', $vehicle->id)->pluck('id');
            $purchaseIds = Purchase::where('vehicle_id', $vehicle->id)->pluck('id');
            $planIds = InstallmentPlan::whereIn('sale_id', $saleIds)->pluck('id');
            $investorSaleIds = InvestorSale::whereIn('sale_id', $saleIds)->pluck('id');
            $installmentIds = Installment::whereIn('plan_id', $planIds)->orWhereIn('investor_sale_id', $investorSaleIds)->pluck('id');
            $promiseIds = DuePromise::whereIn('sale_id', $saleIds)->pluck('id');
            // cancelled payments too: they still belong to the sale or purchase (they are only hidden from totals)
            $paymentIds = Payment::withoutGlobalScope(NotCancelledScope::class)->whereIn('sale_id', $saleIds)->pluck('id');
            $supplierPayments = PurchasePayment::withoutGlobalScope(NotCancelledScope::class)->whereIn('purchase_id', $purchaseIds)->get();

            // supplier payments made from an advance: that advance is the supplier's again (a cancelled one already gave it back)
            $supplierOf = Purchase::whereIn('id', $purchaseIds)->pluck('supplier_id', 'id');
            foreach ($supplierPayments->where('method', 'advance')->whereNull('cancelled_at') as $p) {
                $supplierId = $supplierOf[$p->purchase_id] ?? null;
                if ($supplierId) {
                    $this->giveBackAdvance($supplierId, Money::paisa($p->amount));
                }
            }

            $this->log($vehicle, $saleIds, $purchaseIds, $investorSaleIds, $user);

            CallLog::where(fn ($q) => $q->where('kind', 'installment')->whereIn('ref_id', $installmentIds))
                ->orWhere(fn ($q) => $q->where('kind', 'promise')->whereIn('ref_id', $promiseIds))->delete();
            TransactionEdit::where(fn ($q) => $q->where('type', 'customer_payment')->whereIn('subject_id', $paymentIds))
                ->orWhere(fn ($q) => $q->where('type', 'supplier_payment')->whereIn('subject_id', $supplierPayments->pluck('id')))->delete();

            Payment::withoutGlobalScope(NotCancelledScope::class)->whereIn('id', $paymentIds)->delete();
            SaleCharge::whereIn('sale_id', $saleIds)->delete();
            DuePromise::whereIn('id', $promiseIds)->delete();
            RegistrationLog::whereIn('sale_id', $saleIds)->delete();
            SaleEdit::whereIn('sale_id', $saleIds)->delete();
            InvestorSettlement::whereIn('investor_sale_id', $investorSaleIds)->orWhereIn('plan_id', $planIds)->delete();
            Installment::whereIn('id', $installmentIds)->delete();
            InvestorSale::whereIn('id', $investorSaleIds)->delete();
            InstallmentPlan::whereIn('id', $planIds)->delete();
            Booking::where('vehicle_id', $vehicle->id)->orWhereIn('sale_id', $saleIds)->delete();
            Draft::where('vehicle_id', $vehicle->id)->delete();

            // sales and purchases point at each other (a sale's purchase, a buy-back's adjusted sale)
            Purchase::whereIn('id', $purchaseIds)->update(['adjusted_sale_id' => null]);
            Sale::whereIn('id', $saleIds)->delete();
            PurchaseEdit::whereIn('purchase_id', $purchaseIds)->delete();
            PurchasePayment::withoutGlobalScope(NotCancelledScope::class)->whereIn('id', $supplierPayments->pluck('id'))->delete();
            Purchase::whereIn('id', $purchaseIds)->delete();

            $vehicle->forceDelete();
        });
    }

    /**
     * Keeps a record of what is about to be deleted: who and when, the vehicle's numbers, and each sale, purchase and
     * investor sale with its money, so the deletion can be looked up afterwards.
     *
     * @param  Collection<int, int>  $saleIds
     * @param  Collection<int, int>  $purchaseIds
     * @param  Collection<int, int>  $investorSaleIds
     */
    private function log(Vehicle $vehicle, Collection $saleIds, Collection $purchaseIds, Collection $investorSaleIds, ?User $user): void
    {
        $sales = Sale::with(['customer:id,name', 'plan', 'payments'])->whereIn('id', $saleIds)->get()->map(fn (Sale $s) => [
            'id' => $s->id, 'date' => $s->sale_date->toDateString(), 'customer' => $s->customer?->name, 'payment_type' => $s->payment_type,
            'status' => $s->status, 'total' => $s->grandTotal(), 'paid' => Money::sum($s->payments, 'amount'), 'due' => (float) $s->due_amount,
        ])->values();
        $purchases = Purchase::with(['supplier:id,name', 'customer:id,name'])->whereIn('id', $purchaseIds)->get()->map(fn (Purchase $p) => [
            'id' => $p->id, 'date' => $p->purchase_date->toDateString(), 'from' => $p->supplier->name ?? $p->customer->name ?? null,
            'price' => (float) $p->price, 'paid' => (float) $p->paid_amount,
        ])->values();
        $investorSales = InvestorSale::with(['investor:id,name', 'settlements'])->whereIn('id', $investorSaleIds)->get()->map(fn (InvestorSale $x) => [
            'id' => $x->id, 'investor' => $x->investor?->name, 'principal' => (float) $x->principal,
            'received' => Money::sum($x->settlements->where('type', 'investment'), 'amount'),
            'paid_back' => Money::sum($x->settlements->whereIn('type', ['principal_return', 'profit_share']), 'amount'),
        ])->values();

        VehicleDeletion::create([
            'vehicle_id' => $vehicle->id, 'chassis_no' => $vehicle->chassis_no, 'engine_no' => $vehicle->engine_no, 'registration_no' => $vehicle->registration_no,
            'vehicle_name' => trim("{$vehicle->brand} {$vehicle->model} {$vehicle->year}") ?: null, 'deleted_by' => $user?->id,
            'summary' => [
                'sales' => $sales, 'purchases' => $purchases, 'investor_sales' => $investorSales,
                'totals' => [
                    'customer_paid' => Money::sum($sales, 'paid'), 'supplier_paid' => Money::sum($purchases, 'paid'),
                    'investor_received' => Money::sum($investorSales, 'received'), 'investor_paid_back' => Money::sum($investorSales, 'paid_back'),
                ],
            ],
        ]);
    }

    /** Puts an amount (in paisa) back on the supplier's used advances, newest first. */
    private function giveBackAdvance(int $supplierId, int $amount): void
    {
        $advances = Advance::where('supplier_id', $supplierId)->where('used', '>', 0)->orderByDesc('paid_at')->orderByDesc('id')->lockForUpdate()->get();

        foreach ($advances as $advance) {
            if ($amount <= 0) {
                break;
            }

            $take = min($amount, Money::paisa($advance->used));
            $advance->update(['used' => Money::taka(Money::paisa($advance->used) - $take)]);
            $amount -= $take;
        }
    }
}
