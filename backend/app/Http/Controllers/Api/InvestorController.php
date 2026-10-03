<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvestorProfitFormulaRequest;
use App\Http\Requests\InvestorRequest;
use App\Http\Resources\InvestorResource;
use App\Http\Resources\InvestorSettlementResource;
use App\Http\Resources\SaleResource;
use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Services\InvestorPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvestorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:investors.view', only: ['index', 'show', 'portfolio', 'vehiclePayments']),
            new Middleware('permission:investors.create', only: ['store']),
            new Middleware('permission:investors.edit', only: ['update', 'saveProfitFormula']),
            new Middleware('permission:investors.delete', only: ['destroy']),
        ];
    }

    /** Keeps the formula behind an investor's profit: the three figures entered, with the monthly and yearly rate worked out here. */
    public function saveProfitFormula(InvestorProfitFormulaRequest $request, Investor $investor): JsonResponse
    {
        $invested = round((float) $request->invested, 2);
        $profit = round((float) $request->profit, 2);
        $months = (int) $request->months;
        $month = round($profit / $invested * 100 / $months, 4);

        $investor->update([
            'formula_invested' => $invested, 'formula_profit' => $profit, 'formula_months' => $months,
            'formula_rate_month' => $month, 'formula_rate_year' => round($month * 12, 4),
        ]);

        return $this->success(InvestorResource::make($investor), 'Profit formula saved');
    }

    /** What each vehicle the investor financed has paid back so far, and what is still to be paid. */
    public function vehiclePayments(Investor $investor): JsonResponse
    {
        $plans = $investor->plans()->with(['sale.vehicle:id,chassis_no,brand,model', 'sale.party:id,name', 'installments:id,plan_id,due_date,status'])
            ->whereHas('sale', fn ($q) => $q->where('status', 'active'))->get();
        $paid = InvestorSettlement::where('investor_id', $investor->id)->whereIn('plan_id', $plans->pluck('id'))
            ->selectRaw('plan_id, type, SUM(amount) as total')->groupBy('plan_id', 'type')->get()->groupBy('plan_id');

        $rows = $plans->map(function ($plan) use ($paid) {
            $by = ($paid[$plan->id] ?? collect())->pluck('total', 'type');
            $profit = round((float) $plan->interest_amount * $plan->investor_share_percent / 100, 2);
            $returned = round((float) ($by['principal_return'] ?? 0), 2);
            $profitPaid = round((float) ($by['profit_share'] ?? 0), 2);
            $vehicle = $plan->sale->vehicle;
            $monthly = $plan->number_of_installments > 0 ? round(($plan->principal + $profit) / $plan->number_of_installments, 2) : 0.0;
            $dueCount = $plan->installments->filter(fn ($i) => $i->due_date->lte(today()))->count();

            return [
                'plan_id' => $plan->id, 'sale_id' => $plan->sale_id, 'vehicle' => $vehicle->chassis_no,
                'vehicle_name' => trim("{$vehicle->brand} {$vehicle->model}"), 'buyer' => $plan->sale->party->name,
                'principal' => (float) $plan->principal, 'principal_paid' => $returned, 'principal_due' => max(round($plan->principal - $returned, 2), 0),
                'profit' => $profit, 'profit_paid' => $profitPaid, 'profit_due' => max(round($profit - $profitPaid, 2), 0),
                // the investor's share is paid in step with the plan: principal + profit spread over the installments
                'installments' => (int) $plan->number_of_installments,
                'next_date' => $plan->installments->whereNotIn('status', ['paid', 'cancelled'])->sortBy('due_date')->first()?->due_date?->toDateString(),
                'installments_due' => $plan->installments->whereNotIn('status', ['paid', 'cancelled'])->filter(fn ($i) => $i->due_date->lte(today()))->count(),
                'installments_left' => $plan->installments->whereNotIn('status', ['paid', 'cancelled'])->count(),
                'monthly' => $monthly,
                // owed so far = the installments whose date has come, less what was paid; total due = everything still unpaid
                'current_due' => max(round(min($monthly * $dueCount, $plan->principal + $profit) - $returned - $profitPaid, 2), 0),
                'total_due' => max(round($plan->principal + $profit - $returned - $profitPaid, 2), 0),
            ];
        })->values();

        return $this->success([
            'rows' => $rows,
            'totals' => ['principal_due' => round($rows->sum('principal_due'), 2), 'profit_due' => round($rows->sum('profit_due'), 2), 'paid' => round($rows->sum('principal_paid') + $rows->sum('profit_paid'), 2), 'monthly' => round($rows->sum('monthly'), 2), 'current_due' => round($rows->sum('current_due'), 2), 'total_due' => round($rows->sum('total_due'), 2), 'installments_due' => (int) $rows->sum('installments_due')],
        ]);
    }

    /** Adds what each investor is owed now: the installment dates that have come, less what was paid to the investor. */
    private function attachDue($investors): void
    {
        $paid = InvestorSettlement::whereIn('investor_id', $investors->pluck('id'))->whereIn('type', ['principal_return', 'profit_share'])
            ->selectRaw('plan_id, SUM(amount) as total')->groupBy('plan_id')->pluck('total', 'plan_id');
        $investors->each(function (Investor $i) use ($paid) {
            $due = 0.0;
            $count = 0;
            foreach ($i->plans()->with('installments:id,plan_id,due_date,status')->whereHas('sale', fn ($q) => $q->where('status', 'active'))->get() as $plan) {
                $profit = (float) $plan->interest_amount * $plan->investor_share_percent / 100;
                $monthly = $plan->number_of_installments > 0 ? ($plan->principal + $profit) / $plan->number_of_installments : 0;
                $come = $plan->installments->filter(fn ($x) => $x->due_date->lte(today()));
                $due += max(min($monthly * $come->count(), $plan->principal + $profit) - (float) ($paid[$plan->id] ?? 0), 0);
                $count += $come->whereNotIn('status', ['paid', 'cancelled'])->count();
            }
            $i->setAttribute('current_due', round($due, 2));
            $i->setAttribute('installments_due', $count);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $query = Investor::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('alt_phone', 'like', "%$s%")))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest('id');

        if ($request->boolean('due')) {
            // only investors who are owed something now
            $all = $query->get();
            $this->attachDue($all);
            $due = $all->filter(fn ($i) => $i->current_due > 0)->values();
            $per = $request->integer('per_page', 15);
            $page = max($request->integer('page', 1), 1);
            $investors = new LengthAwarePaginator($due->forPage($page, $per)->values(), $due->count(), $per, $page);

            return $this->paginated($investors, InvestorResource::class);
        }

        $investors = $query->paginate($request->integer('per_page', 15));
        $this->attachDue($investors->getCollection());

        return $this->paginated($investors, InvestorResource::class);
    }

    public function store(InvestorRequest $request): JsonResponse
    {
        $investor = Investor::create($request->validated());

        return $this->success(InvestorResource::make($investor), 'Investor created', 201);
    }

    public function show(Investor $investor): JsonResponse
    {
        return $this->success(InvestorResource::make($investor));
    }

    public function update(InvestorRequest $request, Investor $investor): JsonResponse
    {
        $investor->update($request->validated());

        return $this->success(InvestorResource::make($investor), 'Investor updated');
    }

    public function destroy(Investor $investor): JsonResponse
    {
        if ($investor->sales()->exists() || $investor->settlements()->exists()) {
            throw new BusinessRuleException('This investor has sales or settlements and cannot be deleted. Mark them inactive instead.');
        }

        $investor->delete();

        return $this->success(null, 'Investor deleted');
    }

    public function portfolio(Investor $investor, InvestorPortfolioService $portfolio): JsonResponse
    {
        return $this->success([
            'investor' => InvestorResource::make($investor),
            'summary' => $portfolio->summary($investor),
            'sales' => SaleResource::collection($investor->sales()->with(['vehicle', 'party', 'plan'])->latest('id')->get()),
            'settlements' => InvestorSettlementResource::collection($investor->settlements()->latest('date')->latest('id')->get()),
        ]);
    }
}
