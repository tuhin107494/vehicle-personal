<?php

namespace App\Services;

use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Support\Money;

class InvestorPortfolioService
{
    public function summary(Investor $investor): array
    {
        $byType = InvestorSettlement::where('investor_id', $investor->id)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');


        // Outstanding only counts plans whose sale is still active; a cancelled sale is no longer owed.
        $activePlans = $investor->plans()->whereHas('sale', fn ($q) => $q->where('status', 'active'))->get();
        $outstanding = $activePlans->sum(function ($plan) {
            $returnedForPlan = InvestorSettlement::where('plan_id', $plan->id)->where('type', 'principal_return')->sum('amount');

            return max(Money::paisa($plan->principal) - Money::paisa($returnedForPlan), 0);
        });

        $sales = $investor->sales()->where('status', 'active');

        return [
            'opening_balance' => (float) $investor->opening_balance,
            // SQL sums of decimal columns are exact already
            'invested' => (float) ($byType['investment'] ?? 0),
            'returned' => (float) ($byType['principal_return'] ?? 0),
            'profit_earned' => (float) ($byType['profit_share'] ?? 0),
            'withdrawn' => (float) ($byType['withdrawal'] ?? 0),
            'outstanding' => Money::taka($outstanding),
            'sales_count' => (clone $sales)->count(),
            'collected' => (float) Payment::whereIn('sale_id', (clone $sales)->pluck('id'))->whereNotNull('installment_id')->sum('amount'),
            'sales_due' => (float) (clone $sales)->sum('due_amount'),
        ];
    }
}
