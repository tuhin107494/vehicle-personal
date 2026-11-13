<?php

namespace App\Support;

/**
 * The feature keys the code checks (FeatureGateService::canUse / limit). The values per plan live in the database
 * (plan_features), never here; this list only says which keys exist and what kind each is, so a typo is caught.
 */
final class FeatureCatalog
{
    public const BOOLEAN = 'boolean';

    public const LIMIT = 'limit';

    /** @return array<int, array{0:string,1:string,2:string}> [key, type, description] */
    public static function all(): array
    {
        return [
            ['max_staff', self::LIMIT, 'Staff accounts a shop may have (owner included)'],
            ['max_vehicles_in_stock', self::LIMIT, 'Vehicles a shop may hold in stock at once'],
            ['max_sales_per_month', self::LIMIT, 'Sales a shop may record in a calendar month'],
            ['investors', self::BOOLEAN, 'Investor funding and settlements'],
            ['installments', self::BOOLEAN, 'Installment plans on sales'],
            ['bookings', self::BOOLEAN, 'Bookings with an advance'],
            ['reports', self::BOOLEAN, 'The Reports pages and statements'],
            ['customer_import', self::BOOLEAN, 'Bulk customer import from CSV'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 0);
    }

    public static function typeOf(string $key): ?string
    {
        foreach (self::all() as [$k, $type]) {
            if ($k === $key) {
                return $type;
            }
        }

        return null;
    }
}
