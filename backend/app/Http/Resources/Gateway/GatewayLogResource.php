<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\GatewayLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GatewayLog */
class GatewayLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'attempt_id' => $this->attempt_id,
            'gateway' => $this->gateway_code,
            'direction' => $this->direction,
            'action' => $this->action,
            'http_status' => $this->http_status,
            'ip' => $this->ip,
            'payload' => $this->payload, // scrubbed before it was written
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
