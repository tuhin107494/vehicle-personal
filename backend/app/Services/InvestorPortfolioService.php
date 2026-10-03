<?php

namespace App\Services;

use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Payment;

class InvestorPortfolioService
{
    public function summary(Investor $investor): array
    {
        $byType = InvestorSettlement::where('investor_id', $investor->id)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $invested = (float) ($byType['investment'] ?? 0);
        $returned = (float) ($byType['principal_return'] ?? 0);

        // Outstanding only counts plans whose sale is still active; a cancelled sale is no longer owed.
        $activePlans = $investor->plans()->whereHas('sale', fn ($q) => $q->where('status', 'active'))->get();
        $outstanding = $activePlans->sum(function ($plan) {
            $returnedForPlan = (float) InvestorSettlement::where('plan_id', $plan->id)->where('type', 'principal_return')->sum('amount');

            return max($plan->principal - $returnedForPlan, 0);
        });

        $sales = $investor->sales()->where('status', 'active');

        return [
            'opening_balance' => (float) $investor->opening_balance,
            'invested' => round($invested, 2),
            'returned' => round($returned, 2),
            'profit_earned' => round((float) ($byType['profit_share'] ?? 0), 2),
            'withdrawn' => round((float) ($byType['withdrawal'] ?? 0), 2),
            'outstanding' => round($outstanding, 2),
            'sales_count' => (clone $sales)->count(),
            'collected' => round((float) Payment::whereIn('sale_id', (clone $sales)->pluck('id'))->whereNotNull('installment_id')->sum('amount'), 2),
            'sales_due' => round((float) (clone $sales)->sum('due_amount'), 2),
        ];
    }
}
