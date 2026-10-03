<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DuePromiseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            // an open promise whose date has passed is a broken one
            'status' => $this->status === 'open' && $this->promised_date->lt(today()) ? 'broken' : $this->status,
        ];
    }
}
