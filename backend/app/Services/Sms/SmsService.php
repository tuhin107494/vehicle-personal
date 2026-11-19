<?php

namespace App\Services\Sms;

use App\Exceptions\BusinessRuleException;
use App\Jobs\SendSmsJob;
use App\Models\Customer;
use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\Sale;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Models\SmsLog;
use App\Services\Billing\SubscriptionService;
use App\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one way an SMS leaves the app: it is logged, queued on the "sms" queue, checked again just before it goes,
 * and sent through the gateway set in .env (with the fallback gateway when the first one fails).
 * Customers get due alerts only: upcoming, due today, overdue and a final reminder, at most one per due per stage.
 */
class SmsService
{
    public const QUEUE = 'sms';

    /** An SMS still "queued" this long after it was queued has lost its job (a worker restart, or the sync queue outside the sending hours). */
    private const STALE_AFTER_MINUTES = 60;

    /**
     * Per-shop data read once per operation (shop, settings, templates, whether it may send), so a run of many SMS does not read them per SMS.
     * Every public operation starts it afresh: a controller can live across requests, and settings may change in between.
     *
     * @var array<string,array<int,mixed>>
     */
    private array $memo = [];

    public function __construct(private SubscriptionService $subscriptions) {}

    /**
     * The scheduler's run (every few minutes): each shop that sends due alerts is run once a day, at or after its own send time.
     * Two queries when every shop has already run today.
     *
     * @return int SMS queued
     */
    public function queueDueAlerts(): int
    {
        $this->memo = [];
        $now = now(config('sms.timezone'));
        $shops = Shop::where('is_active', true)->whereNotNull('approved_at')
            ->whereIn('id', ShopSetting::withoutGlobalScope(ShopScope::class)->select('shop_id')->where('key', 'sms.enabled')->where('value', '1'))
            ->get()->keyBy('id');
        $queued = 0;

        foreach (SmsSettings::forShops($shops->keys()->all()) as $shopId => $settings) {
            $this->memo['shop'][$shopId] = $shops[$shopId];
            $this->memo['settings'][$shopId] = $settings;
            $ranToday = $settings['last_run'] !== null && str_starts_with($settings['last_run'], $now->toDateString());

            if (! $ranToday && $now->format('H:i') >= $settings['send_time'] && $this->canSend($shopId)) {
                $queued += $this->run($shopId);
            }
        }

        return $queued;
    }

    /**
     * Runs one shop's due alerts now (the "Run now" button). Safe to repeat: a due that already had a stage does not get it again.
     *
     * @return int SMS queued
     */
    public function runShop(int $shopId): int
    {
        $this->memo = [];

        if (! $this->canSend($shopId)) {
            throw new BusinessRuleException('Turn on SMS due alerts first.');
        }

        return $this->run($shopId);
    }

    /** A test SMS to a staff number, so the shop can see the gateway and the text work before turning alerts on. */
    public function queueTest(int $shopId, string $phone): SmsLog
    {
        $this->memo = [];
        $shop = Shop::findOrFail($shopId);
        $log = SmsLog::create([
            'shop_id' => $shopId,
            'phone' => $phone,
            'message' => "Test SMS from {$shop->name}. Due alerts are set up.",
            'status' => 'queued',
        ]);

        $this->dispatch($log);

        return $log;
    }

    /** Puts a failed SMS back on the queue. A due alert is checked again before it goes, so a due paid since then is skipped. */
    public function retry(SmsLog $log): SmsLog
    {
        $this->memo = [];
        $log->update(['status' => 'queued', 'response' => null]);
        $this->dispatch($log);

        return $log;
    }

    /**
     * Sends a queued SMS: called by SendSmsJob. One job at a time per SMS (a lock), so an SMS that was queued again
     * while its first job was still waiting never goes out twice. Throws SmsException when every gateway failed, so the job tries again.
     */
    public function deliver(int $logId): void
    {
        $this->memo = [];
        // without the lock, another job is sending this SMS (or will try again later): this one has nothing to do
        Cache::lock("sms-log:{$logId}", 120)->get(fn () => $this->send($logId));
    }

    /** The job gave up after its retries: the SMS shows on the failed list. */
    public function markFailed(int $logId, string $why): void
    {
        SmsLog::withoutGlobalScope(ShopScope::class)->whereKey($logId)->where('status', 'queued')
            ->update(['status' => 'failed', 'response' => mb_substr($why, 0, 1000)]);
    }

    /** The main gateway, for the balance check. */
    public function gateway(): SmsGateway
    {
        return $this->gateways()[0];
    }

    /** 01XXXXXXXXX, +8801XXXXXXXXX or 8801XXXXXXXXX as 8801XXXXXXXXX; null for anything that is not a Bangladeshi mobile number. */
    public static function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $digits = match (true) {
            str_starts_with($digits, '880') => $digits,
            str_starts_with($digits, '0') => '88'.$digits,
            default => '880'.$digits,
        };

        return preg_match('/^8801[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    /**
     * Queues the shop's due alerts for today: every unpaid installment and promised due whose date is a stage day away
     * (3 days ahead, today, 1 and 7 days past by default) gets that stage's SMS, unless it already had it.
     * Two queries find the dues of every stage, with their sale and customer; each SMS then costs only its own insert.
     */
    private function run(int $shopId): int
    {
        $this->requeueStale($shopId);
        $active = array_column(array_filter($this->templates($shopId), fn ($t) => $t['is_active']), 'stage');
        $stageOn = []; // due date => the stage that looks at it today (each stage looks at a different day)

        foreach (SmsSettings::offsets($this->settings($shopId)) as $stage => $offset) {
            if (in_array($stage, $active, true)) {
                $stageOn[today()->subDays($offset)->toDateString()] = $stage;
            }
        }

        $dues = collect();

        if ($stageOn !== []) {
            $days = array_keys($stageOn);
            $dues = $this->installments($shopId)->whereIn('status', ['pending', 'partial', 'overdue'])->where(self::onDays('due_date', $days))->get()
                ->concat($this->promises($shopId)->whereIn('status', ['open', 'broken'])->where(self::onDays('promised_date', $days))->get());
        }

        $queued = 0;

        foreach ($dues as $due) {
            $day = ($due instanceof Installment ? $due->due_date : $due->promised_date)->toDateString();

            try {
                $this->queueDueAlert($due, $stageOn[$day]) && $queued++;
            } catch (Throwable $e) {
                report($e); // one bad row must not stop the others
            }
        }

        SmsSettings::recordRun($shopId, $queued);

        return $queued;
    }

    /**
     * Logs and queues one due alert. Returns null when this due already had this stage (the unique index stops a second one).
     * The message is built again from fresh data when the job sends it.
     */
    private function queueDueAlert(Installment|DuePromise $due, string $stage): ?SmsLog
    {
        $info = $this->dueInfo($due);
        $message = $info ? $this->message($due->shop_id, $stage, $info) : null;

        if ($message === null) {
            return null;
        }

        try {
            $log = SmsLog::create([
                'shop_id' => $due->shop_id,
                'customer_id' => $info['customer']->id,
                'sale_id' => $info['sale']->id,
                'due_type' => $due instanceof Installment ? 'installment' : 'promise',
                'due_id' => $due->id,
                'stage' => $stage,
                'phone' => $info['customer']->phone,
                'message' => $message,
                'status' => 'queued',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // already sent (or queued) at this stage
        }

        $this->dispatch($log);

        return $log;
    }

    /**
     * The shop's SMS still "queued" an hour after they were queued have lost their job: they go on the queue again.
     * Done in the shop's daily run, not on every scheduler tick, so an SMS waiting for the sending hours is not queued again and again.
     */
    private function requeueStale(int $shopId): void
    {
        SmsLog::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('status', 'queued')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->each(function (SmsLog $log) {
                $log->touch();
                $this->dispatch($log);
            });
    }

    /** What deliver() does while it holds the SMS's lock. */
    private function send(int $logId): void
    {
        // by id from our own queue: the worker has no signed-in user, and a test run may have one from another shop
        $log = SmsLog::withoutGlobalScope(ShopScope::class)->find($logId);

        if (! $log || $log->status !== 'queued') {
            return;
        }

        if ($wait = $this->outsideSendingHours($log->shop_id)) {
            // a real queue holds it until the window opens; the sync queue cannot wait, so it stays queued for the next daily run
            config('queue.default') !== 'sync' && $this->dispatch($log, $wait);

            return;
        }

        if ($log->due_type) {
            $due = $log->due_type === 'installment'
                ? $this->installments($log->shop_id)->find($log->due_id)
                : $this->promises($log->shop_id)->find($log->due_id);
            $info = $due ? $this->dueInfo($due) : null;
            $message = $info ? $this->message($log->shop_id, $log->stage, $info) : null;

            if (! $info || $message === null) {
                $log->update(['status' => 'skipped', 'response' => 'Not sent: the due was paid or cancelled, or reminders were turned off.']);

                return;
            }

            $log->fill(['message' => $message, 'phone' => $info['customer']->phone]);
        }

        $phone = self::normalize($log->phone);

        if (! $phone) {
            $log->update(['status' => 'skipped', 'response' => 'Not sent: not a Bangladeshi mobile number.']);

            return;
        }

        $parts = SmsTemplates::parts($log->message);
        $log->fill(['phone' => $phone, 'parts' => $parts, 'cost' => round($parts * (float) config('sms.cost_per_part'), 2)]);
        $errors = [];

        foreach ($this->gateways() as $gateway) {
            try {
                $id = $gateway->send($phone, $log->message);
                $log->fill(['status' => 'sent', 'provider' => $gateway->name(), 'provider_msg_id' => $id ?: null, 'response' => null, 'sent_at' => now()])->save();

                return;
            } catch (SmsException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $log->fill(['response' => implode(' | ', $errors)])->save();

        throw new SmsException(implode(' | ', $errors));
    }

    /**
     * A shop's installments, with what a due alert reads (the sale and its customer) loaded in the same go.
     * Without the shop scope, as the scheduler and the queue have no signed-in user: the shop is named instead.
     *
     * @return Builder<Installment>
     */
    private function installments(int $shopId): Builder
    {
        return Installment::withoutGlobalScope(ShopScope::class)->with(self::relations(Installment::class))->where('shop_id', $shopId);
    }

    /**
     * A shop's promised dues, loaded like installments().
     *
     * @return Builder<DuePromise>
     */
    private function promises(int $shopId): Builder
    {
        return DuePromise::withoutGlobalScope(ShopScope::class)->with(self::relations(DuePromise::class))->where('shop_id', $shopId);
    }

    /**
     * @param  class-string<Installment|DuePromise>  $model
     * @return array<string,Closure>
     */
    private static function relations(string $model): array
    {
        $unscoped = fn ($q) => $q->withoutGlobalScope(ShopScope::class);

        return $model === Installment::class
            ? ['plan' => $unscoped, 'plan.sale' => $unscoped, 'plan.sale.customer' => $unscoped]
            : ['sale' => $unscoped, 'sale.customer' => $unscoped];
    }

    /**
     * A condition for rows whose date falls on one of the days. Written as ranges, not DATE(column), so the database can use
     * the date index (and a date stored with a midnight time still matches).
     *
     * @param  list<string>  $days  Y-m-d
     */
    private static function onDays(string $column, array $days): Closure
    {
        return function ($q) use ($column, $days) {
            foreach ($days as $day) {
                $q->orWhere(fn ($q) => $q->where($column, '>=', $day)->where($column, '<', Carbon::parse($day)->addDay()->toDateString()));
            }
        };
    }

    /**
     * What a due alert needs, read fresh: null when no alert should go (paid, cancelled, reminders off for the customer or the sale).
     *
     * @return array{customer:Customer,sale:Sale,amount:float,due_date:Carbon,ref:string}|null
     */
    private function dueInfo(Installment|DuePromise $due): ?array
    {
        $due->loadMissing(self::relations($due::class)); // installments() and promises() loaded them already: no query

        if ($due instanceof Installment) {
            $sale = $due->plan?->sale;
            $amount = in_array($due->status, ['pending', 'partial', 'overdue'], true) ? $due->remaining() : 0.0;
            $date = $due->due_date;
            $ref = '#'.($sale?->memo_no ?: $sale?->id).'/'.$due->seq_no;
        } else {
            $sale = $due->sale;
            $amount = in_array($due->status, ['open', 'broken'], true) && $sale ? min((float) $due->promised_amount, (float) $sale->due_amount) : 0.0;
            $date = $due->promised_date;
            $ref = '#'.($sale?->memo_no ?: $sale?->id);
        }

        $customer = $sale?->customer;

        if (! $sale || ! $customer || (int) $sale->shop_id !== (int) $due->shop_id || $sale->status !== 'active' || ! $sale->sms_enabled || ! $customer->sms_enabled || Money::paisa($amount) <= 0) {
            return null;
        }

        return ['customer' => $customer, 'sale' => $sale, 'amount' => $amount, 'due_date' => $date, 'ref' => $ref];
    }

    /** The stage's text in the shop's language, filled in; null when the shop switched that stage off or alerts off. */
    private function message(int $shopId, string $stage, array $info): ?string
    {
        $template = $this->canSend($shopId) ? $this->templates($shopId)[SmsTemplates::KEYS[$stage]] : null;

        if (! $template || ! $template['is_active']) {
            return null;
        }

        $shop = $this->shop($shopId);
        $amount = (float) $info['amount'];

        return SmsTemplates::render($this->settings($shopId)['language'] === 'en' ? $template['body_en'] : $template['body_bn'], [
            'name' => $info['customer']->name,
            'amount' => number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2),
            'due_date' => $info['due_date']->format('d-m-Y'),
            'ref' => $info['ref'],
            'office_phone' => (string) $shop?->phone,
            'shop' => (string) $shop?->name,
        ]);
    }

    /** Whether the shop may send due alerts at all: switched on, open, and not locked by an ended subscription. */
    private function canSend(int $shopId): bool
    {
        return $this->remember('can_send', $shopId, function () use ($shopId) {
            $shop = $this->shop($shopId);

            return $shop && $shop->is_active && ! $shop->isPending() && $this->settings($shopId)['enabled'] && ! $this->subscriptions->locked($shop);
        });
    }

    private function shop(int $shopId): ?Shop
    {
        return $this->remember('shop', $shopId, fn () => Shop::find($shopId));
    }

    /** @return array<string,mixed> */
    private function settings(int $shopId): array
    {
        return $this->remember('settings', $shopId, fn () => SmsSettings::forShop($shopId));
    }

    /** @return array<string,array<string,mixed>> */
    private function templates(int $shopId): array
    {
        return $this->remember('templates', $shopId, fn () => SmsTemplates::forShop($shopId));
    }

    private function remember(string $what, int $shopId, Closure $load): mixed
    {
        if (! array_key_exists($shopId, $this->memo[$what] ?? [])) {
            $this->memo[$what][$shopId] = $load();
        }

        return $this->memo[$what][$shopId];
    }

    /** @return list<SmsGateway> the main gateway, then the fallback one when set */
    private function gateways(): array
    {
        $names = array_values(array_unique(array_filter([config('sms.driver'), config('sms.fallback')])));

        return array_map(fn (string $name) => match ($name) {
            'smsnetbd' => new SmsNetBdGateway,
            'bulksmsbd' => new BulkSmsBdGateway,
            default => new LogGateway,
        }, $names ?: ['log']);
    }

    /** SMS go out only within the shop's sending hours; outside them, when the next window opens. */
    private function outsideSendingHours(int $shopId): ?Carbon
    {
        $settings = $this->settings($shopId);
        $now = now(config('sms.timezone'));
        $from = $settings['send_from'];

        if ($now->hour >= $from && $now->hour < $settings['send_until']) {
            return null;
        }

        $next = $now->copy()->setTime($from, 0);

        return $now->hour >= $from ? $next->addDay() : $next;
    }

    private function dispatch(SmsLog $log, ?Carbon $at = null): void
    {
        $at ??= $this->outsideSendingHours($log->shop_id);
        $pending = SendSmsJob::dispatch($log->id)->onQueue(self::QUEUE)->afterCommit();

        if ($at) {
            $pending->delay($at);
        }
    }
}
