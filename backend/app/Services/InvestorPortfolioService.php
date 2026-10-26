<?php

namespace App\Services;

use App\Models\Investor;
use App\Models\InstallmentPlan;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Collection;

class InvestorPortfolioService
{
    public function summary(Investor $investor): array
    {
        return $this->summaries(collect([$investor]))[$investor->id];
    }

    /**
     * The summary of every given investor, worked out with a handful of grouped queries instead of a set of queries per investor.
     *
     * @param  Collection<int,Investor>  $investors
     * @return array<int,array<string,mixed>> investor id => summary
     */
    public function summaries(Collection $investors): array
    {
        $ids = $investors->pluck('id');

        $byType = InvestorSettlement::whereIn('investor_id', $ids)
            ->selectRaw('investor_id, type, SUM(amount) as total')
            ->groupBy('investor_id', 'type')
            ->get()->groupBy('investor_id');

        // Outstanding only counts plans whose sale is still active; a cancelled sale is no longer owed.
        $plans = InstallmentPlan::whereIn('investor_id', $ids)->whereHas('sale', fn ($q) => $q->where('status', 'active'))
            ->get(['id', 'investor_id', 'principal'])->groupBy('investor_id');
        $returned = InvestorSettlement::whereIn('plan_id', $plans->flatten()->pluck('id'))->where('type', 'principal_return')
            ->selectRaw('plan_id, SUM(amount) as total')->groupBy('plan_id')->pluck('total', 'plan_id');

        $sales = Sale::whereIn('investor_id', $ids)->where('status', 'active')->get(['id', 'investor_id', 'due_amount'])->groupBy('investor_id');
        $collected = Payment::whereIn('sale_id', $sales->flatten()->pluck('id'))->whereNotNull('installment_id')
            ->selectRaw('sale_id, SUM(amount) as total')->groupBy('sale_id')->pluck('total', 'sale_id');

        return $investors->mapWithKeys(function (Investor $investor) use ($byType, $plans, $returned, $sales, $collected) {
            $type = ($byType[$investor->id] ?? collect())->pluck('total', 'type');
            $mine = $sales[$investor->id] ?? collect();

            $outstanding = ($plans[$investor->id] ?? collect())->sum(fn ($plan) => max(Money::paisa($plan->principal) - Money::paisa($returned[$plan->id] ?? 0), 0));

            return [$investor->id => [
                'opening_balance' => (float) $investor->opening_balance,
                // SQL sums of decimal columns are exact already
                'invested' => (float) ($type['investment'] ?? 0),
                'returned' => (float) ($type['principal_return'] ?? 0),
                'profit_earned' => (float) ($type['profit_share'] ?? 0),
                'withdrawn' => (float) ($type['withdrawal'] ?? 0),
                'outstanding' => Money::taka($outstanding),
                'sales_count' => $mine->count(),
                'collected' => (float) $mine->sum(fn ($sale) => (float) ($collected[$sale->id] ?? 0)),
                'sales_due' => (float) $mine->sum('due_amount'),
            ]];
        })->all();
    }
}
