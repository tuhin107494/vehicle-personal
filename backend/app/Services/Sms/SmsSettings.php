<?php

namespace App\Services\Sms;

use App\Models\Scopes\ShopScope;
use App\Models\ShopSetting;

/**
 * A shop's due-alert settings: on or off (off until the shop turns it on), the language (Bangla by default), the days of each stage,
 * when the daily run sends (10:00 by default), the hours SMS may go out (9 am to 8 pm by default), and when it last ran.
 * Read by shop id, so the scheduler and the queue can use it too.
 */
class SmsSettings
{
    public const DEFAULT_DAYS = ['upcoming' => 3, 'overdue' => 1, 'final' => 7];

    public const DEFAULT_SEND_TIME = '10:00';

    /**
     * @return array{enabled:bool,language:string,days:array{upcoming:int,overdue:int,final:int},send_time:string,send_from:int,send_until:int,last_run:?string,last_count:?int}
     */
    public static function forShop(int $shopId): array
    {
        return self::forShops([$shopId])[$shopId];
    }

    /**
     * Several shops' settings in one query (the scheduler reads every shop with alerts on, every few minutes).
     *
     * @param  list<int>  $shopIds
     * @return array<int,array{enabled:bool,language:string,days:array{upcoming:int,overdue:int,final:int},send_time:string,send_from:int,send_until:int,last_run:?string,last_count:?int}>
     */
    public static function forShops(array $shopIds): array
    {
        // explicit shop filter: the scheduler and the queue have no signed-in user
        $rows = $shopIds === [] ? collect() : ShopSetting::withoutGlobalScope(ShopScope::class)
            ->whereIn('shop_id', $shopIds)->where('key', 'like', 'sms.%')->get(['shop_id', 'key', 'value'])->groupBy('shop_id');
        $out = [];

        foreach ($shopIds as $shopId) {
            $out[$shopId] = self::fromValues(($rows[$shopId] ?? collect())->pluck('value', 'key')->all());
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $raw  key => value of one shop's sms.* settings
     * @return array{enabled:bool,language:string,days:array{upcoming:int,overdue:int,final:int},send_time:string,send_from:int,send_until:int,last_run:?string,last_count:?int}
     */
    private static function fromValues(array $raw): array
    {
        $days = [];

        foreach (self::DEFAULT_DAYS as $stage => $default) {
            $days[$stage] = (int) ($raw["sms.days.$stage"] ?? $default) ?: $default;
        }

        return [
            'enabled' => ($raw['sms.enabled'] ?? '0') === '1',
            'language' => ($raw['sms.language'] ?? 'bn') === 'en' ? 'en' : 'bn',
            'days' => $days,
            'send_time' => $raw['sms.send_time'] ?? self::DEFAULT_SEND_TIME,
            'send_from' => isset($raw['sms.send_from']) ? (int) $raw['sms.send_from'] : (int) config('sms.send_from'),
            'send_until' => isset($raw['sms.send_until']) ? (int) $raw['sms.send_until'] : (int) config('sms.send_until'),
            'last_run' => $raw['sms.last_run'] ?? null,
            'last_count' => isset($raw['sms.last_count']) ? (int) $raw['sms.last_count'] : null,
        ];
    }

    /**
     * Each stage's distance from the due date in days, as in due_alert_days = [-3, 0, 1, 7].
     *
     * @return array<string,int> stage => days after the due date (negative: before it)
     */
    public static function offsets(array $settings): array
    {
        return ['upcoming' => -$settings['days']['upcoming'], 'today' => 0, 'overdue' => $settings['days']['overdue'], 'final' => $settings['days']['final']];
    }

    /** Saves what the settings form sent under "sms"; called inside ShopSettings::save's transaction, for the signed-in user's shop. */
    public static function save(array $input): void
    {
        $values = [
            'sms.enabled' => isset($input['enabled']) ? ($input['enabled'] ? '1' : '0') : null,
            'sms.language' => $input['language'] ?? null,
            'sms.send_time' => $input['send_time'] ?? null,
            'sms.send_from' => isset($input['send_from']) ? (string) $input['send_from'] : null,
            'sms.send_until' => isset($input['send_until']) ? (string) $input['send_until'] : null,
        ];

        foreach (array_keys(self::DEFAULT_DAYS) as $stage) {
            $values["sms.days.$stage"] = isset($input['days'][$stage]) ? (string) $input['days'][$stage] : null;
        }

        foreach (array_filter($values, fn ($v) => $v !== null) as $key => $value) {
            ShopSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /** Notes the day's run (and how many SMS it queued), so the scheduler runs each shop once a day. Written by shop id: the scheduler has no user. */
    public static function recordRun(int $shopId, int $count): void
    {
        foreach (['sms.last_run' => now(config('sms.timezone'))->format('Y-m-d H:i'), 'sms.last_count' => (string) $count] as $key => $value) {
            ShopSetting::withoutGlobalScope(ShopScope::class)->updateOrCreate(['shop_id' => $shopId, 'key' => $key], ['value' => $value]);
        }
    }
}
