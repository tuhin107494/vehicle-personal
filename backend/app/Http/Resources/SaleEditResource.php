<?php

namespace App\Http\Resources;

use App\Models\SaleEdit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One edit of a sale: who made it, when, and each field's change.
 *
 * @mixin SaleEdit
 */
class SaleEditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'by' => $this->user?->name,
            'at' => $this->created_at->toIso8601String(),
            'changes' => $this->changes,
        ];
    }
}
