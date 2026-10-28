<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerPageLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'shop_id' => $shop->id]));
    }

    public function test_lists_take_a_per_page_from_1_to_100(): void
    {
        $this->getJson('/api/customers?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/customers?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/customers')->assertOk()->assertJsonPath('meta.per_page', 15);
    }

    public function test_per_page_above_100_or_not_a_number_is_refused(): void
    {
        foreach (['101', '0', '-5', 'abc', '10.5'] as $bad) {
            $this->getJson("/api/vehicles?per_page=$bad")->assertStatus(422)->assertJsonValidationErrors('per_page');
        }
    }
}
