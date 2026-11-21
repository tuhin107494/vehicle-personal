<?php

namespace App\Http\Resources;

use App\Models\InvestorSale;
use App\Services\InvestorSaleService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvestorSale */
class InvestorSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'investor' => InvestorResource::make($this->whenLoaded('investor')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            // with its payments loaded: what the investor has given the shop of their amount, and still owes it
            'investor_paid' => $this->whenLoaded('settlements', fn () => Money::sum($this->settlements->where('type', 'investment'), 'amount')),
            'investor_owes' => $this->whenLoaded('settlements', fn () => Money::taka(max(Money::paisa($this->principal) - Money::paisa(Money::sum($this->settlements->where('type', 'investment'), 'amount')), 0))),
            'schedule' => array_map(fn (array $row) => [
                'seq_no' => $row['seq_no'], 'due_date' => $row['due_date']->toDateString(), 'amount' => Money::taka($row['amount']),
            ], $this->resource->schedule()),
            // with its payments loaded: what has been paid and what is owed now
            'position' => $this->whenLoaded('settlements', fn () => InvestorSaleService::inTaka(app(InvestorSaleService::class)->position(
                $this->resource,
                Money::paisa($this->settlements->where('type', 'principal_return')->sum('amount')),
                Money::paisa($this->settlements->where('type', 'profit_share')->sum('amount')),
            ))),
        ];
    }
}
