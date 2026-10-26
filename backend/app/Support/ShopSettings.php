<?php

namespace App\Support;

use App\Models\ShopSetting;

/** Per-shop presets: what the purchase and sale forms fill in when a vehicle type is chosen. */
class ShopSettings
{
    /** @return array<string,mixed> the shop's presets, kept per vehicle type */
    public static function all(): array
    {
        // A price only makes sense for a vehicle type, so prices and field presets are all keyed by type.
        return [
            'purchase' => ['vehicle_type' => self::primaryType('purchase'), 'prices' => (object) self::prices('purchase')],
            'sale' => ['vehicle_type' => self::primaryType('sale'), 'prices' => (object) self::prices('sale')],
            'type_defaults' => (object) self::typeDefaults(),
            'collections' => ['recall_days' => self::recallDays()],
        ];
    }

    public const DEFAULT_RECALL_DAYS = 7;

    /** After a due-alert row is marked "call done", it comes back after this many days. */
    public static function recallDays(): int
    {
        return (int) (ShopSetting::where('key', 'collections.recall_days')->value('value') ?: self::DEFAULT_RECALL_DAYS);
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
        if (isset($input['collections']['recall_days'])) {
            ShopSetting::updateOrCreate(['key' => 'collections.recall_days'], ['value' => $input['collections']['recall_days']]);
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
    }
}
