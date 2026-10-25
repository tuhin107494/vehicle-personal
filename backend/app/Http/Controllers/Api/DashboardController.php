<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DuePromiseResource;
use App\Http\Resources\InstallmentResource;
use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
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

    public function __invoke(): JsonResponse
    {
        $overdue = fn () => Installment::where(fn ($q) => $q
            ->where('status', 'overdue')
            ->orWhere(fn ($q) => $q->whereIn('status', ['pending', 'partial'])->whereDate('due_date', '<', today())));

        // installments falling due today that are not fully paid
        $dueToday = fn () => Installment::whereIn('status', ['pending', 'partial', 'overdue'])->whereDate('due_date', today());

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
            'vehicles' => ['in_stock' => Vehicle::where('status', 'in_stock')->count(), 'sold' => Vehicle::where('status', 'sold')->count()],
            'sale_types' => $payments,
            'counts' => ['customers' => Customer::count(), 'suppliers' => Supplier::count(), 'investors' => Investor::count()],
            'payable' => (float) $unpaid,
            'invested' => (float) InvestorSettlement::where('type', 'investment')->sum('amount'),
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
                $dueToday()->with(['plan.sale.vehicle', 'plan.sale.customer'])->orderBy('id')->limit(5)->get()
            ),
            'installments_due_today_count' => $dueToday()->count(),
            'promises_due_today' => DuePromiseResource::collection(
                DuePromise::with(['sale.vehicle', 'sale.customer'])->where('status', 'open')->whereDate('promised_date', today())->get()
            ),
        ]);
    }
}
