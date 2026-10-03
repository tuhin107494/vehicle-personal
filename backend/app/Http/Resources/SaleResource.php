<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'grand_total' => $this->loadMissing('plan')->grandTotal(),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'investor' => InvestorResource::make($this->whenLoaded('investor')),
            'purchase' => PurchaseResource::make($this->whenLoaded('purchase')),
            'plan' => InstallmentPlanResource::make($this->whenLoaded('plan')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'promises' => DuePromiseResource::collection($this->whenLoaded('promises')),
            'registration_logs' => RegistrationLogResource::collection($this->whenLoaded('registrationLogs')),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator->only(['id', 'name'])),
        ];
    }
}
