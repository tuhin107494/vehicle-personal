<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            // an unpaid installment past its date is overdue, whatever was last stored
            'status' => in_array($this->status, ['pending', 'partial'], true) && $this->due_date->lt(today()) ? 'overdue' : $this->status,
            'plan' => InstallmentPlanResource::make($this->whenLoaded('plan')),
        ];
    }
}
