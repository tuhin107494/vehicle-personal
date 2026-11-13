<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\GatewayConfig;
use App\Support\Photos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gateway as a shop sees it at checkout: name, logo, description, nothing about how it is set up.
 *
 * @mixin GatewayConfig
 */
class GatewayOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'display_name' => $this->display_name,
            'logo_url' => Photos::url($this->logo_path),
            'description' => $this->description,
            'mode' => $this->active_mode, // a shop paying in sandbox should know it is a test
        ];
    }
}
