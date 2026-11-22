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
use App\Models\TransactionEdit;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class VehicleService
{
    /**
     * Removes a vehicle for good with everything recorded on it: its purchases and supplier payments, its sales with their
     * installments, promises, charges and customer payments, its investor sales with the investor's entries, and its bookings.
     * Meant for a vehicle entered by mistake (a second copy with a mistyped chassis number). The chassis, engine and
     * registration numbers are free to be entered again afterwards.
     */
    public function delete(Vehicle $vehicle): void
    {
        DB::transaction(function () use ($vehicle) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);

            $saleIds = Sale::where('vehicle_id', $vehicle->id)->pluck('id');
            $purchaseIds = Purchase::where('vehicle_id', $vehicle->id)->pluck('id');
            $planIds = InstallmentPlan::whereIn('sale_id', $saleIds)->pluck('id');
            $investorSaleIds = InvestorSale::whereIn('sale_id', $saleIds)->pluck('id');
            $installmentIds = Installment::whereIn('plan_id', $planIds)->orWhereIn('investor_sale_id', $investorSaleIds)->pluck('id');
            $promiseIds = DuePromise::whereIn('sale_id', $saleIds)->pluck('id');
            $paymentIds = Payment::whereIn('sale_id', $saleIds)->pluck('id');
            $supplierPayments = PurchasePayment::whereIn('purchase_id', $purchaseIds)->get();

            // supplier payments made from an advance: that advance is the supplier's again
            foreach ($supplierPayments->where('method', 'advance') as $p) {
                $supplierId = Purchase::find($p->purchase_id)?->supplier_id;
                if ($supplierId) {
                    $this->giveBackAdvance($supplierId, Money::paisa($p->amount));
                }
            }

            CallLog::where(fn ($q) => $q->where('kind', 'installment')->whereIn('ref_id', $installmentIds))
                ->orWhere(fn ($q) => $q->where('kind', 'promise')->whereIn('ref_id', $promiseIds))->delete();
            TransactionEdit::where(fn ($q) => $q->where('type', 'customer_payment')->whereIn('subject_id', $paymentIds))
                ->orWhere(fn ($q) => $q->where('type', 'supplier_payment')->whereIn('subject_id', $supplierPayments->pluck('id')))->delete();

            Payment::whereIn('id', $paymentIds)->delete();
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
            PurchasePayment::whereIn('id', $supplierPayments->pluck('id'))->delete();
            Purchase::whereIn('id', $purchaseIds)->delete();

            $vehicle->forceDelete();
        });
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
