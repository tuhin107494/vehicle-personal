<?php

namespace Tests\Unit;

use App\Support\PermissionCatalog;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_permission_keys_are_unique(): void
    {
        $keys = array_column(PermissionCatalog::all(), 0);

        $this->assertSame(array_unique($keys), $keys);
    }

    public function test_admin_starts_with_every_permission(): void
    {
        $this->assertSame(array_column(PermissionCatalog::all(), 0), PermissionCatalog::adminDefaults());
    }

    public function test_moderator_starts_without_deletes_cancellations_settlements_reports_or_settings(): void
    {
        $defaults = PermissionCatalog::moderatorDefaults();

        foreach ($defaults as $key) {
            $this->assertStringEndsNotWith('.delete', $key);
        }
        foreach (['sales.cancel', 'settlements.view', 'settlements.manage', 'reports.view', 'settings.manage'] as $key) {
            $this->assertNotContains($key, $defaults);
        }
        foreach (['sales.create', 'payments.collect', 'purchases.create', 'parties.edit'] as $key) {
            $this->assertContains($key, $defaults);
        }
    }

    public function test_only_admin_and_moderator_permissions_can_be_changed_by_a_shop_owner(): void
    {
        $this->assertSame(['admin', 'moderator'], PermissionCatalog::ASSIGNABLE_ROLES);
        $this->assertEmpty(array_diff(PermissionCatalog::ASSIGNABLE_ROLES, array_keys(PermissionCatalog::ROLES)));
    }
}
