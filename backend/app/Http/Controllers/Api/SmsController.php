<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SmsSwitchRequest;
use App\Http\Requests\SmsTemplateRequest;
use App\Http\Requests\SmsTestRequest;
use App\Http\Resources\SmsLogResource;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsSettings;
use App\Services\Sms\SmsTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The shop's SMS due alerts: the log with its monthly count and cost, retrying a failed SMS, the templates, a test SMS, and the switches. */
class SmsController extends Controller implements HasMiddleware
{
    public function __construct(private SmsService $sms) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:sms.view', only: ['index']),
            new Middleware('permission:sms.manage', only: ['retry', 'customerSwitch', 'saleSwitch']),
            new Middleware('permission:settings.manage', only: ['templates', 'updateTemplate', 'test', 'runNow']),
        ];
    }

    /** The SMS of a month (this month by default), newest first, with the month's totals. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(['queued', 'sent', 'delivered', 'failed', 'skipped'])],
            'stage' => ['nullable', Rule::in(SmsLog::STAGES)],
        ]);

        $month = Carbon::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'))->startOfMonth();
        $inMonth = fn () => SmsLog::whereBetween('created_at', [$month, $month->copy()->endOfMonth()]);

        $logs = $inMonth()->with('customer:id,name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            ->latest('id')
            ->paginate($this->perPage($request));

        // the month's totals in one grouped query
        $totals = $inMonth()->toBase()->selectRaw('status, COUNT(*) as n, COALESCE(SUM(parts), 0) as parts, COALESCE(SUM(cost), 0) as cost')
            ->groupBy('status')->get()->keyBy('status');
        $sent = $totals->only(['sent', 'delivered']);
        $count = fn (string $status) => (int) ($totals->get($status)->n ?? 0);

        return $this->paginated($logs, SmsLogResource::class, 'OK', ['summary' => [
            'month' => $month->format('Y-m'),
            'sent' => (int) $sent->sum('n'),
            'failed' => $count('failed'),
            'skipped' => $count('skipped'),
            'queued' => $count('queued'),
            'parts' => (int) $sent->sum('parts'),
            'cost' => round((float) $sent->sum('cost'), 2),
            'live' => config('sms.driver') !== 'log',
        ]]);
    }

    /** A failed SMS goes back on the queue; it is checked again before it is sent. */
    public function retry(SmsLog $smsLog): JsonResponse
    {
        if ($smsLog->status !== 'failed') {
            throw new BusinessRuleException('Only a failed SMS can be sent again.');
        }

        return $this->success(SmsLogResource::make($this->sms->retry($smsLog)->fresh()->load('customer:id,name')), 'SMS queued again');
    }

    /** The four due-alert templates: the shop's own wording over the built-in text. */
    public function templates(Request $request): JsonResponse
    {
        return $this->success(array_values(SmsTemplates::forShop($request->user()->shop_id)), 'OK', 200, ['placeholders' => SmsTemplates::PLACEHOLDERS]);
    }

    public function updateTemplate(SmsTemplateRequest $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, SmsTemplates::KEYS, true), 404);

        SmsTemplate::updateOrCreate(['key' => $key], $request->validated());

        return $this->success(SmsTemplates::forShop($request->user()->shop_id)[$key], 'Template saved');
    }

    /** Sends a test SMS to a staff number through the queue, like any other SMS. */
    public function test(SmsTestRequest $request): JsonResponse
    {
        $log = $this->sms->queueTest($request->user()->shop_id, $request->validated('phone'));

        return $this->success(SmsLogResource::make($log->fresh()), $log->fresh()->status === 'sent' ? 'Test SMS sent' : 'Test SMS queued', 201);
    }

    /** Runs today's due alerts for this shop now, instead of waiting for its send time. A due that already had a stage is not sent again. */
    public function runNow(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;
        $count = $this->sms->runShop($shopId);

        return $this->success(SmsSettings::forShop($shopId), $count === 1 ? '1 SMS queued' : "{$count} SMS queued");
    }

    /** Stops or restarts due alerts for every sale of one customer. */
    public function customerSwitch(SmsSwitchRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return $this->success(['sms_enabled' => $customer->sms_enabled], $customer->sms_enabled ? 'SMS reminders turned on' : 'SMS reminders turned off');
    }

    /** Stops or restarts due alerts for one sale (its installments and promised dues). */
    public function saleSwitch(SmsSwitchRequest $request, Sale $sale): JsonResponse
    {
        $sale->update($request->validated());

        return $this->success(['sms_enabled' => $sale->sms_enabled], $sale->sms_enabled ? 'SMS reminders turned on' : 'SMS reminders turned off');
    }
}
