<?php

namespace App\Http\Resources;

use App\Models\Sale;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'grand_total' => ($this->payment_type === 'installment' ? $this->loadMissing('plan') : $this)->grandTotal(),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'additional_customer' => CustomerResource::make($this->whenLoaded('additionalCustomer')),
            'investor' => InvestorResource::make($this->whenLoaded('investor')),
            'purchase' => PurchaseResource::make($this->whenLoaded('purchase')),
            // the advance paid when the vehicle was booked, and the day it was received
            'booking' => BookingResource::make($this->whenLoaded('booking')),
            // money received before the sale: the booking's advance, or an advance payment recorded with the sale
            'advance' => $this->advance(),
            'plan' => InstallmentPlanResource::make($this->whenLoaded('plan')),
            // the investor sale funding this sale, on its own terms
            'investor_sale' => $this->whenLoaded('investorSale', fn () => $this->investorSale ? InvestorSaleResource::make($this->investorSale) : null),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'promises' => DuePromiseResource::collection($this->whenLoaded('promises')),
            // each charge with what was paid of it: its payments are taken from the sale's, already loaded (no query per charge)
            'charges' => SaleChargeResource::collection($this->whenLoaded('charges', fn () => $this->relationLoaded('payments')
                ? $this->charges->each(fn ($c) => $c->setRelation('payments', $this->payments->where('sale_charge_id', $c->id)->values()))
                : $this->charges)),
            'edits' => SaleEditResource::collection($this->whenLoaded('edits')),
            'registration_logs' => RegistrationLogResource::collection($this->whenLoaded('registrationLogs')),
            'creator' => UserResource::make($this->whenLoaded('creator')),
        ];
    }

    /** @return array{amount: float, date: string, method: string, booking_id: int|null}|null */
    private function advance(): ?array
    {
        if ($this->relationLoaded('booking') && $this->booking && (float) $this->booking->advance_amount > 0) {
            return ['amount' => (float) $this->booking->advance_amount, 'date' => $this->booking->booked_on->toDateString(), 'method' => $this->booking->advance_method, 'booking_id' => $this->booking->id];
        }

        $paid = $this->relationLoaded('payments') ? $this->payments->where('note', 'Advance') : collect();

        return $paid->isEmpty() ? null : [
            'amount' => Money::sum($paid, 'amount'), 'date' => $paid->min('paid_at')->toDateString(), 'method' => $paid->first()->method, 'booking_id' => null,
        ];
    }
}
