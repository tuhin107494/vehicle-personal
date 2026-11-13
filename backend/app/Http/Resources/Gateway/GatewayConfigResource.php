<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\GatewayConfig;
use App\Support\Photos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gateway as the super admin's list and configure pages see it: never a credential value. `details` is added by show().
 *
 * @mixin GatewayConfig
 */
class GatewayConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'display_name' => $this->display_name,
            'logo_url' => Photos::url($this->logo_path),
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'active_mode' => $this->active_mode,
            'min_amount_paisa' => $this->min_amount_paisa,
            'max_amount_paisa' => $this->max_amount_paisa,
            'fee_percent' => (float) $this->fee_percent,
            'fee_fixed_paisa' => $this->fee_fixed_paisa,
            'webhook_ips' => $this->webhook_ips,
            'configured' => $this->additional['configured'] ?? null,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'details' => $this->when(isset($this->additional['details']), fn () => $this->additional['details']),
        ];
    }
}
