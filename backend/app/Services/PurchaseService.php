<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(private PaymentService $payments) {}

    /** Facts about the vehicle that can be refreshed on every purchase (chassis and engine numbers never change). */
    private const DETAIL_FIELDS = [
        'registration_no', 'vehicle_type', 'brand', 'model', 'year', 'color', 'cc', 'fuel_type',
        'registration_date', 'tax_token_expiry', 'fitness_expiry', 'insurance_expiry',
        'papers_received', 'mileage', 'condition_notes',
    ];

    private const VEHICLE_FIELDS = ['chassis_no', 'engine_no', ...self::DETAIL_FIELDS];

    public function create(array $data, User $user): Purchase
    {
        return DB::transaction(function () use ($data, $user) {
            if (! empty($data['vehicle_id'])) {
                $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);

                if ($vehicle->status !== 'sold') {
                    throw new BusinessRuleException('This vehicle is already in stock and cannot be purchased again until it is sold.');
                }

                $vehicle->update(Arr::only($data, self::DETAIL_FIELDS));
            } else {
                $vehicle = Vehicle::create(Arr::only($data, self::VEHICLE_FIELDS) + ['status' => 'in_stock']);
            }

            $price = Money::paisa($data['price']);
            $paid = Money::paisa($data['paid_amount'] ?? 0);
            $adjustment = Money::paisa($data['due_adjustment'] ?? 0);
            $sale = $this->checkedAdjustment($data, $vehicle, $price, $paid, $adjustment);

            $purchase = Purchase::create([
                'vehicle_id' => $vehicle->id,
                'supplier_id' => ($data['source'] ?? 'supplier') === 'customer' ? null : ($data['supplier_id'] ?? null),
                'customer_id' => ($data['source'] ?? 'supplier') === 'customer' ? ($data['customer_id'] ?? null) : null,
                'source' => $data['source'] ?? 'supplier',
                'memo_no' => $data['memo_no'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'price' => Money::taka($price),
                'paid_amount' => Money::taka($paid),
                'due_adjustment' => Money::taka($adjustment),
                'adjusted_sale_id' => $sale?->id,
                'cost_extra' => Money::taka(Money::paisa($data['cost_extra'] ?? 0)),
                'payment_status' => $this->paymentStatus($price, $paid + $adjustment),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $vehicle->update([
                'status' => 'in_stock',
                'current_purchase_id' => $purchase->id,
                'current_sale_id' => null,
            ]);

            if ($sale) {
                // Set the amount off against what the customer owes: a payment on their sale, allocated like any other.
                $this->payments->collect($sale, [
                    'amount' => Money::taka($adjustment),
                    'method' => 'adjustment',
                    'paid_at' => $data['purchase_date'],
                    'note' => "Set off against buy-back purchase #{$purchase->id}",
                ], $user);
            }

            return $purchase;
        });
    }

    public function update(Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $data) {
            // the same lock a supplier payment takes, so a payment cannot land between these checks and the save
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);

            // amounts are kept to the paisa, the same as every calculation on them
            foreach (['price', 'paid_amount', 'cost_extra'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = Money::taka(Money::paisa($data[$field]));
                }
            }

            // once payments are recorded, paid_amount is their running total: only new payments may change it
            if (isset($data['paid_amount']) && Money::paisa($data['paid_amount']) !== Money::paisa($purchase->paid_amount)
                && $purchase->payments()->exists()) {
                throw new BusinessRuleException('Payments were recorded against this purchase, so the paid amount can only change through payments.');
            }

            $price = Money::paisa($data['price'] ?? $purchase->price);
            $settled = Money::paisa($data['paid_amount'] ?? $purchase->paid_amount) + Money::paisa($purchase->due_adjustment);

            if ($settled > $price) {
                throw new BusinessRuleException('Paid amount and the due adjustment cannot be more than the purchase price.');
            }

            $purchase->update($data + ['payment_status' => $this->paymentStatus($price, $settled)]);

            return $purchase;
        });
    }

    public function delete(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            // the same locks a supplier payment (purchase) and a sale (vehicle) take, so neither can slip in between these checks and the delete
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            $vehicle = Vehicle::lockForUpdate()->findOrFail($purchase->vehicle_id);

            if ($purchase->adjusted_sale_id) {
                throw new BusinessRuleException('This purchase settled part of a customer\'s due and cannot be deleted.');
            }

            if ($vehicle->status === 'booked') {
                throw new BusinessRuleException('This vehicle is booked. Cancel the booking first.');
            }

            if ($purchase->payments()->exists()) {
                throw new BusinessRuleException('Payments were recorded against this purchase, so it cannot be deleted.');
            }

            if ($purchase->sales()->exists()) {
                throw new BusinessRuleException('This purchase has sales recorded against it and cannot be deleted.');
            }

            $purchase->delete();

            $previous = $vehicle->purchases()->latest('purchase_date')->latest('id')->first();

            if (! $previous) {
                $vehicle->delete();

                return;
            }

            $vehicle->update([
                'status' => 'sold',
                'current_purchase_id' => $previous->id,
                'current_sale_id' => $previous->sales()->where('status', 'active')->latest('id')->value('id'),
            ]);
        });
    }

    /**
     * @param  int  $price  in paisa
     * @param  int  $paid  in paisa: paid plus any due set off
     */
    private function paymentStatus(int $price, int $paid): string
    {
        if ($paid >= $price) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    /** Validates a due adjustment (amounts in paisa) and returns the sale of this vehicle it applies to (null when there is none). */
    private function checkedAdjustment(array $data, Vehicle $vehicle, int $price, int $paid, int $adjustment): ?Sale
    {
        if ($adjustment <= 0) {
            return null;
        }

        if (($data['source'] ?? 'supplier') !== 'customer') {
            throw new BusinessRuleException('A due can only be adjusted on a purchase from a customer.');
        }

        $sale = Sale::lockForUpdate()->find($data['adjusted_sale_id'] ?? 0);

        if (! $sale || $sale->status !== 'active' || $sale->customer_id !== (int) ($data['customer_id'] ?? 0)) {
            throw new BusinessRuleException('Choose one of this customer\'s active sales to adjust the due against.');
        }

        // Only the due on the vehicle being bought back can be set off, never the customer's other sales.
        if ($sale->vehicle_id !== $vehicle->id) {
            throw new BusinessRuleException('The due can only be adjusted against the sale of the vehicle being bought back.');
        }

        if ($adjustment > Money::paisa($sale->due_amount)) {
            throw new BusinessRuleException('The adjustment is more than this customer owes on that sale ('.number_format($sale->due_amount, 2).').');
        }

        if ($adjustment + $paid > $price) {
            throw new BusinessRuleException('The adjustment and the amount paid are more than the purchase price.');
        }

        return $sale;
    }
}
