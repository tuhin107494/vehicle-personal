<?php

namespace App\Http\Resources;

use App\Models\PurchaseEdit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One edit of a purchase: who made it, when, and each field's change.
 *
 * @mixin PurchaseEdit
 */
class PurchaseEditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'by' => $this->user?->name, 'at' => $this->created_at->toIso8601String(), 'changes' => $this->changes];
    }
}
