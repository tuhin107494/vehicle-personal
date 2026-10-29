<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'balance' => $this->agreed_price === null ? null : Money::add($this->agreed_price, -$this->advance_amount),
            // whole days until the deadline (negative once it has passed)
            'days_left' => $this->status === 'active' && $this->expires_on ? (int) today()->diffInDays($this->expires_on, false) : null,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle?->only(['id', 'chassis_no', 'engine_no', 'brand', 'model', 'year', 'vehicle_type', 'status'])),
            'booked_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer->only(['id', 'name', 'phone', 'alt_phone'])),
        ];
    }
}
