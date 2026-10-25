<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DuePromiseResource;
use App\Http\Resources\InstallmentResource;
use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\InstallmentPlan;
use App\Models\InvestorSettlement;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DashboardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:dashboard.view'),
        ];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $overdue = fn () => Installment::where(fn ($q) => $q
            ->where('status', 'overdue')
            ->orWhere(fn ($q) => $q->whereIn('status', ['pending', 'partial'])->whereDate('due_date', '<', today())));

        // installments falling due today that are not fully paid
        $dueToday = fn () => Installment::whereIn('status', ['pending', 'partial', 'overdue'])->whereDate('due_date', today());

        // what the investors are owed today: the plans they financed that have an installment falling due today, at the investor's monthly share
        $investorToday = InstallmentPlan::whereNotNull('investor_id')
            ->whereHas('sale', fn ($q) => $q->where('status', 'active'))
            ->whereHas('installments', fn ($q) => $q->whereDate('due_date', today())->where('status', '!=', 'cancelled'))
            ->with(['investor:id,name,phone', 'sale.vehicle:id,chassis_no,engine_no'])->get()
            ->map(function (InstallmentPlan $plan) {
                $profit = (float) $plan->interest_amount * $plan->investor_share_percent / 100;

                return [
                    'plan_id' => $plan->id, 'sale_id' => $plan->sale_id,
                    'investor' => $plan->investor->only(['id', 'name', 'phone']),
                    'vehicle' => $plan->sale->vehicle->only(['chassis_no', 'engine_no']),
                    'amount' => $plan->number_of_installments > 0 ? round(($plan->principal + $profit) / $plan->number_of_installments, 2) : 0.0,
                ];
            })->values();

        // today's business and cash, the same figures as the daily report (only for users who may see reports)
        $today = null;
        if ($request->user()->hasPermission('reports.view')) {
            $daily = app(ReportController::class)->daily(Request::create('/', 'GET', ['date' => today()->toDateString()]))->getData(true)['data'];
            $today = [
                'sales_count' => $daily['sales']['count'], 'sales_total' => $daily['sales']['total'],
                'purchases_count' => $daily['purchases']['count'], 'purchases_total' => $daily['purchases']['total'],
                'cash_in' => $daily['cash']['in'], 'cash_out' => $daily['cash']['out'], 'cash_net' => $daily['cash']['net'],
            ];
        }

        $monthSales = Sale::where('status', 'active')
            ->whereBetween('sale_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        $monthPurchases = Purchase::whereBetween('purchase_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        // the last six months side by side: what was sold, bought and collected
        $months = collect(range(5, 0))->map(function ($ago) {
            $start = now()->startOfMonth()->subMonths($ago);
            $end = $start->copy()->endOfMonth();
            $range = [$start->toDateString(), $end->toDateString()];

            return [
                'month' => $start->format('M'),
                'sales' => (float) Sale::where('status', 'active')->whereBetween('sale_date', $range)->sum('sale_price'),
                'purchases' => (float) Purchase::whereBetween('purchase_date', $range)->sum('price'),
                'collected' => (float) Payment::where('amount', '>', 0)->whereBetween('paid_at', [$start->startOfDay(), $end->endOfDay()])->sum('amount'),
            ];
        })->values();

        $stockByType = Vehicle::where('status', 'in_stock')->selectRaw('vehicle_type, COUNT(*) as total')->groupBy('vehicle_type')->pluck('total', 'vehicle_type');
        $unpaid = Purchase::query()->selectRaw('COALESCE(SUM(CASE WHEN price - paid_amount - due_adjustment > 0 THEN price - paid_amount - due_adjustment ELSE 0 END), 0) as total')->value('total');
        $payments = Sale::where('status', 'active')->selectRaw('payment_type, COUNT(*) as total')->groupBy('payment_type')->pluck('total', 'payment_type');

        return $this->success([
            'months' => $months,
            'stock_by_type' => $stockByType,
            // the same, with each type's display name, so the dashboard needs no second request for the type list
            'stock_types' => $stockByType->map(fn ($count, $key) => ['key' => $key, 'name' => VehicleCategory::where('key', $key)->value('name') ?? ucfirst(str_replace('_', ' ', (string) $key)), 'count' => $count])->sortByDesc('count')->values(),
            'vehicles' => ['in_stock' => Vehicle::where('status', 'in_stock')->count(), 'sold' => Vehicle::where('status', 'sold')->count()],
            'sale_types' => $payments,
            'counts' => ['customers' => Customer::count(), 'suppliers' => Supplier::count(), 'investors' => Investor::count()],
            'payable' => round((float) $unpaid, 2),
            // things that need a look: registrations still waiting, and vehicles that have sat in stock for over 30 days
            'registration_pending' => Sale::where('status', 'active')->where('registration_status', 'pending')->count(),
            'old_stock' => Vehicle::where('status', 'in_stock')->whereHas('currentPurchase', fn ($q) => $q->whereDate('purchase_date', '<', today()->subDays(30)))->count(),
            'invested' => round((float) InvestorSettlement::where('type', 'investment')->sum('amount'), 2),
            'stock_count' => Vehicle::where('status', 'in_stock')->count(),
            'sales_this_month' => (clone $monthSales)->count(),
            'sales_amount_this_month' => (float) (clone $monthSales)->sum('sale_price'),
            'total_sales' => Sale::where('status', 'active')->count(),
            'total_sales_amount' => (float) Sale::where('status', 'active')->sum('sale_price'),
            'total_purchases' => Purchase::count(),
            'total_purchases_amount' => (float) Purchase::sum('price'),
            'purchases_this_month' => (clone $monthPurchases)->count(),
            'purchases_amount_this_month' => (float) (clone $monthPurchases)->sum('price'),
            'total_due' => (float) Sale::where('status', 'active')->sum('due_amount'),
            'overdue_count' => $overdue()->count(),
            'installments_due_today' => InstallmentResource::collection(
                $dueToday()->with(['plan.sale.vehicle', 'plan.sale.customer'])->orderBy('id')->limit(3)->get()
            ),
            'installments_due_today_count' => $dueToday()->count(),
            'investor_payments_today' => $investorToday,
            'today' => $today,
            'promises_due_today' => DuePromiseResource::collection(
                DuePromise::with(['sale.vehicle', 'sale.customer'])->where('status', 'open')->whereDate('promised_date', today())->get()
            ),
        ]);
    }
}
