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
            ...parent::toArray($request),
            'grand_total' => ($this->payment_type === 'installment' ? $this->loadMissing('plan') : $this)->grandTotal(),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'investor' => InvestorResource::make($this->whenLoaded('investor')),
            'purchase' => PurchaseResource::make($this->whenLoaded('purchase')),
            // the advance paid when the vehicle was booked, and the day it was received
            'booking' => $this->whenLoaded('booking', fn () => $this->booking ? [
                'id' => $this->booking->id, 'advance_amount' => (float) $this->booking->advance_amount,
                'advance_method' => $this->booking->advance_method, 'booked_on' => $this->booking->booked_on->toDateString(),
                'expires_on' => $this->booking->expires_on?->toDateString(), 'agreed_price' => $this->booking->agreed_price === null ? null : (float) $this->booking->agreed_price,
                'note' => $this->booking->note, 'booked_by' => $this->booking->creator?->name,
            ] : null),
            // money received before the sale: the booking's advance, or an advance payment recorded with the sale
            'advance' => $this->advance(),
            'plan' => InstallmentPlanResource::make($this->whenLoaded('plan')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'promises' => DuePromiseResource::collection($this->whenLoaded('promises')),
            'registration_logs' => RegistrationLogResource::collection($this->whenLoaded('registrationLogs')),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator->only(['id', 'name'])),
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
