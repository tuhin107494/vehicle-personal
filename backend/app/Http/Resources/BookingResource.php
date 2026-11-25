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
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            // money as numbers, like every other response (the columns are decimals, which come out as strings)
            'agreed_price' => $this->agreed_price === null ? null : (float) $this->agreed_price,
            'advance_amount' => (float) $this->advance_amount,
            'refund_amount' => (float) $this->refund_amount,
            'kept_amount' => (float) $this->kept_amount,
            'balance' => $this->agreed_price === null ? null : Money::add($this->agreed_price, -$this->advance_amount),
            // whole days until the deadline (negative once it has passed)
            'days_left' => $this->status === 'active' && $this->expires_on ? (int) today()->diffInDays($this->expires_on, false) : null,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'booked_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
        ];
    }
}
