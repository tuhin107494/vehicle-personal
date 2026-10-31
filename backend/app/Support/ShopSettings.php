<?php

namespace App\Support;

use App\Models\ShopSetting;
use Illuminate\Support\Facades\DB;

/** Per-shop presets: what the purchase and sale forms fill in when a vehicle type is chosen. */
class ShopSettings
{
    /** @return array<string,mixed> the shop's presets, kept per vehicle type */
    public static function all(): array
    {
        // A price only makes sense for a vehicle type, so prices and field presets are all keyed by type.
        return [
            'purchase' => ['vehicle_type' => self::primaryType('purchase'), 'prices' => (object) self::prices('purchase'), 'from_customer' => self::purchaseFromCustomer()],
            'sale' => ['vehicle_type' => self::primaryType('sale'), 'prices' => (object) self::prices('sale')],
            'type_defaults' => (object) self::typeDefaults(),
            'collections' => ['recall_days' => self::recallDays(), 'keep_selection' => self::keepSelection()],
            'forms' => ['auto_date' => self::autoDate(), 'memo_no' => self::memoNo()],
            'booking' => ['days' => self::bookingDays(), 'on_cancel' => self::bookingPolicy('on_cancel'), 'on_expire' => self::bookingPolicy('on_expire')],
        ];
    }

    /** Whether the shop buys second-hand vehicles back from customers (on unless the shop turned it off). */
    public static function purchaseFromCustomer(): bool
    {
        return ShopSetting::where('key', 'purchase.from_customer')->value('value') !== '0';
    }

    public const DEFAULT_RECALL_DAYS = 7;

    /** After a due-alert row is marked "call done", it comes back after this many days. */
    public static function recallDays(): int
    {
        return (int) (ShopSetting::where('key', 'collections.recall_days')->value('value') ?: self::DEFAULT_RECALL_DAYS);
    }

    /** Whether the due collection and supplier payment forms keep the chosen customer or supplier (and vehicle) after a payment (off unless the shop turned it on). */
    public static function keepSelection(): bool
    {
        return ShopSetting::where('key', 'collections.keep_selection')->value('value') === '1';
    }

    /** Whether entry forms start with today's date filled in (off unless the shop turned it on: the date then starts empty). */
    public static function autoDate(): bool
    {
        return ShopSetting::where('key', 'forms.auto_date')->value('value') === '1';
    }

    /** Whether the Sale and Due collection forms show the optional memo number field (on unless the shop turned it off). */
    public static function memoNo(): bool
    {
        return ShopSetting::where('key', 'forms.memo_no')->value('value') !== '0';
    }

    public const DEFAULT_BOOKING_DAYS = 7;

    /** A new booking is held for this many days unless the staff set another deadline. */
    public static function bookingDays(): int
    {
        return (int) (ShopSetting::where('key', 'booking.days')->value('value') ?: self::DEFAULT_BOOKING_DAYS);
    }

    /** What happens to a booking's advance when it is cancelled or expires: "refund" it all, or "keep" it all (staff can still change the amount). */
    public static function bookingPolicy(string $when): string
    {
        return ShopSetting::where('key', "booking.$when")->value('value') === 'keep' ? 'keep' : 'refund';
    }

    /** The vehicle type a form starts on (the Buy vehicle form preselects it, the New sale form filters by it). */
    private static function primaryType(string $group): ?string
    {
        return ShopSetting::where('key', "$group.vehicle_type")->value('value') ?: null;
    }

    /** Fields a shop can preset per vehicle type, overriding the type's own defaults. */
    public const TYPE_FIELDS = ['brand', 'model', 'color', 'cc', 'year', 'fuel_type', 'mileage'];

    private const TYPE_NUMERIC = ['cc', 'year', 'mileage'];

    /** @return array<string,\stdClass> vehicle type key => {field: value}, an object so it stays a JSON object */
    private static function typeDefaults(): array
    {
        $out = [];

        foreach (ShopSetting::where('key', 'like', 'typedef.%')->pluck('value', 'key') as $key => $value) {
            [$field, $type] = explode(':', substr($key, strlen('typedef.')), 2);
            $out[$type][$field] = in_array($field, self::TYPE_NUMERIC, true) ? (int) $value : $value;
        }

        return array_map(fn ($fields) => (object) $fields, $out);
    }

    /** @return array<string,float|int> vehicle type key => price */
    private static function prices(string $group): array
    {
        $prefix = "$group.price:";

        return ShopSetting::where('key', 'like', $prefix.'%')->pluck('value', 'key')
            ->mapWithKeys(fn ($value, $key) => [substr($key, strlen($prefix)) => $value + 0])->all();
    }

    public static function save(array $input): void
    {
        // every key is saved together or none are
        DB::transaction(function () use ($input) {
            if (isset($input['collections']['recall_days'])) {
                ShopSetting::updateOrCreate(['key' => 'collections.recall_days'], ['value' => $input['collections']['recall_days']]);
            }

            if (isset($input['collections']['keep_selection'])) {
                ShopSetting::updateOrCreate(['key' => 'collections.keep_selection'], ['value' => $input['collections']['keep_selection'] ? '1' : '0']);
            }

            if (isset($input['forms']['memo_no'])) {
                ShopSetting::updateOrCreate(['key' => 'forms.memo_no'], ['value' => $input['forms']['memo_no'] ? '1' : '0']);
            }

            if (isset($input['forms']['auto_date'])) {
                ShopSetting::updateOrCreate(['key' => 'forms.auto_date'], ['value' => $input['forms']['auto_date'] ? '1' : '0']);
            }

            if (isset($input['purchase']['from_customer'])) {
                ShopSetting::updateOrCreate(['key' => 'purchase.from_customer'], ['value' => $input['purchase']['from_customer'] ? '1' : '0']);
            }

            foreach (['days', 'on_cancel', 'on_expire'] as $field) {
                if (isset($input['booking'][$field])) {
                    ShopSetting::updateOrCreate(['key' => "booking.$field"], ['value' => $input['booking'][$field]]);
                }
            }

            foreach ($input['type_defaults'] ?? [] as $type => $fields) {
                foreach (self::TYPE_FIELDS as $field) {
                    if (! array_key_exists($field, $fields)) {
                        continue;
                    }

                    $key = "typedef.$field:$type";
                    ($fields[$field] === null || $fields[$field] === '')
                        ? ShopSetting::where('key', $key)->delete()
                        : ShopSetting::updateOrCreate(['key' => $key], ['value' => $fields[$field]]);
                }
            }

            foreach (['purchase', 'sale'] as $group) {
                if (array_key_exists('vehicle_type', $input[$group] ?? [])) {
                    ShopSetting::updateOrCreate(['key' => "$group.vehicle_type"], ['value' => $input[$group]['vehicle_type']]);
                }

                foreach ($input[$group]['prices'] ?? [] as $type => $price) {
                    $key = "$group.price:$type";
                    $price === null ? ShopSetting::where('key', $key)->delete() : ShopSetting::updateOrCreate(['key' => $key], ['value' => $price]);
                }
            }
        });
    }
}
