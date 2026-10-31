<?php

namespace App\Support;

class PermissionCatalog
{
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'shop_owner' => 'Shop Owner',
        'admin' => 'Admin',
        'moderator' => 'Moderator',
    ];

    /** Roles whose permissions a shop owner can change. */
    public const ASSIGNABLE_ROLES = ['admin', 'moderator'];

    /** @return array<int,array{0:string,1:string,2:string}> key, name, group */
    public static function all(): array
    {
        $crud = fn (string $g, string $noun, bool $delete = true, bool $create = true) => array_filter([
            ["$g.view", "View $noun", $g],
            $create ? ["$g.create", "Add $noun", $g] : null,
            ["$g.edit", "Edit $noun", $g],
            $delete ? ["$g.delete", "Delete $noun", $g] : null,
        ]);

        return [
            ['dashboard.view', 'View dashboard', 'dashboard'],
            ...$crud('parties', 'parties'),
            ...$crud('investors', 'investors'),
            ['settlements.view', 'View investor settlements', 'investors'],
            ['settlements.manage', 'Record investor settlements', 'investors'],
            ...$crud('vehicles', 'vehicles', true, false), // vehicles are added through purchases
            ...$crud('purchases', 'purchases'),
            ['sales.view', 'View sales', 'sales'],
            ['sales.create', 'Create sales', 'sales'],
            ['sales.edit', 'Edit sales', 'sales'],
            ['transactions.edit', 'Edit customer and supplier transactions', 'accounts'],
            ['sales.cancel', 'Cancel sales', 'sales'],
            ['payments.collect', 'Collect payments', 'collections'],
            ['promises.view', 'View due promises', 'collections'],
            ['promises.manage', 'Add or renew promises', 'collections'],
            ['collections.view', 'View installment collections', 'collections'],
            ['registration.update', 'Update registration status', 'sales'],
            ['reports.view', 'View reports', 'reports'],
            ['settings.manage', 'Manage shop settings', 'settings'],
        ];
    }

    /** What a new shop's Admin role starts with: everything in the catalog. */
    public static function adminDefaults(): array
    {
        return array_column(self::all(), 0);
    }

    /** What a new shop's Moderator role starts with: day-to-day work, no deletes/cancellations/settlements. */
    public static function moderatorDefaults(): array
    {
        return array_values(array_filter(array_column(self::all(), 0), fn ($k) => ! str_ends_with($k, '.delete')
            && ! in_array($k, ['sales.edit', 'transactions.edit', 'sales.cancel', 'settlements.manage', 'settlements.view', 'reports.view', 'settings.manage'], true)));
    }
}
