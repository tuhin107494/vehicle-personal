<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\WalkIn;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
    }

    private function makeShop(string $name): User
    {
        return app(ShopService::class)->create(['name' => $name], ['name' => "$name owner", 'email' => strtolower($name).'@t.test', 'password' => 'password'])->owner;
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_a_walk_in_is_saved_listed_edited_and_deleted(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);

        $id = $this->postJson('/api/walk-ins', ['phone' => '01712345678', 'address' => 'Narayanganj', 'note' => 'Wants a Pulsar'])
            ->assertCreated()->assertJsonPath('data.saved_by', $owner->name)->json('data.id');
        $this->assertDatabaseHas('walk_ins', ['id' => $id, 'phone' => '01712345678', 'created_by' => $owner->id, 'shop_id' => $owner->shop_id]);

        $this->getJson('/api/walk-ins?search=Pulsar')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/walk-ins?search=nothing-here')->assertOk()->assertJsonPath('meta.total', 0);

        $this->putJson("/api/walk-ins/$id", ['name' => 'Rahim', 'phone' => '01712345678', 'note' => 'Will come back'])->assertOk()->assertJsonPath('data.name', 'Rahim');
        $this->deleteJson("/api/walk-ins/$id")->assertOk();
        $this->assertDatabaseMissing('walk_ins', ['id' => $id]);
    }

    public function test_the_mobile_number_is_required_and_checked(): void
    {
        $this->as($this->makeShop('A'));

        $this->postJson('/api/walk-ins', ['note' => 'x'])->assertJsonValidationErrors('phone');
        $this->postJson('/api/walk-ins', ['phone' => 'abc'])->assertJsonValidationErrors('phone');
        $this->postJson('/api/walk-ins', ['phone' => '01712345678', 'note' => str_repeat('a', 2001)])->assertJsonValidationErrors('note');
        $this->assertDatabaseCount('walk_ins', 0);
    }

    public function test_another_shop_cannot_see_or_change_a_walk_in(): void
    {
        $ownerA = $this->makeShop('A');
        $ownerB = $this->makeShop('B');
        $this->as($ownerA);
        $id = $this->postJson('/api/walk-ins', ['phone' => '01712345678'])->assertCreated()->json('data.id');

        $this->as($ownerB);
        $this->getJson("/api/walk-ins/$id")->assertNotFound();
        $this->putJson("/api/walk-ins/$id", ['phone' => '01799999999'])->assertNotFound();
        $this->deleteJson("/api/walk-ins/$id")->assertNotFound();
        $this->getJson('/api/walk-ins')->assertJsonPath('meta.total', 0);
        $this->assertSame('01712345678', WalkIn::withoutGlobalScopes()->find($id)->phone);
    }

    public function test_a_user_without_the_permission_is_refused_and_super_admin_has_no_shop_access(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);
        $this->postJson('/api/users', ['name' => 'M', 'email' => 'm@t.test', 'password' => 'password1', 'role' => 'moderator', 'permissions' => []])->assertCreated();

        $this->as(User::where('email', 'm@t.test')->first());
        $this->getJson('/api/walk-ins')->assertForbidden();
        $this->postJson('/api/walk-ins', ['phone' => '01712345678'])->assertForbidden();

        $this->as(User::factory()->create(['role' => 'super_admin']));
        $this->getJson('/api/walk-ins')->assertForbidden();
    }

    public function test_the_walk_in_permissions_exist_and_a_moderator_cannot_delete_by_default(): void
    {
        $this->assertSame(4, Permission::where('key', 'like', 'walkins.%')->count());

        $owner = $this->makeShop('A');
        $this->as($owner);
        $moderator = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];

        $this->assertContains('walkins.create', $moderator);
        $this->assertNotContains('walkins.delete', $moderator);
    }

    public function test_a_walk_in_becomes_a_customer_and_is_removed(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);
        $id = $this->postJson('/api/walk-ins', ['phone' => '01712345678', 'address' => 'Narayanganj', 'note' => 'Wants a Pulsar'])->assertCreated()->json('data.id');

        $this->postJson("/api/walk-ins/$id/convert", ['name' => 'Rahim', 'phone' => '01712345678', 'address' => 'Narayanganj', 'notes' => 'Wants a Pulsar'])
            ->assertCreated()->assertJsonPath('data.name', 'Rahim');

        $this->assertDatabaseHas('customers', ['shop_id' => $owner->shop_id, 'phone' => '01712345678', 'name' => 'Rahim']);
        $this->assertDatabaseMissing('walk_ins', ['id' => $id]);
        // a second call finds nothing, so no second customer
        $this->postJson("/api/walk-ins/$id/convert", ['name' => 'Rahim', 'phone' => '01712345678'])->assertNotFound();
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_converting_needs_a_name_and_keeps_the_walk_in_when_the_customer_is_refused(): void
    {
        $this->as($this->makeShop('A'));
        $id = $this->postJson('/api/walk-ins', ['phone' => '01712345678'])->assertCreated()->json('data.id');
        $this->postJson('/api/customers', ['name' => 'Existing', 'phone' => '01799999999'])->assertCreated();

        $this->postJson("/api/walk-ins/$id/convert", ['phone' => '01712345678'])->assertJsonValidationErrors('name');
        $this->postJson("/api/walk-ins/$id/convert", ['name' => 'Dup', 'phone' => '01799999999'])->assertJsonValidationErrors('phone');

        $this->assertDatabaseHas('walk_ins', ['id' => $id]);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_converting_is_refused_without_the_permissions_and_across_shops(): void
    {
        $ownerA = $this->makeShop('A');
        $ownerB = $this->makeShop('B');
        $this->as($ownerA);
        $id = $this->postJson('/api/walk-ins', ['phone' => '01712345678'])->assertCreated()->json('data.id');
        $roles = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];
        $this->postJson('/api/users', ['name' => 'M', 'email' => 'm@t.test', 'password' => 'password1', 'role' => 'moderator', 'permissions' => array_values(array_diff($roles, ['parties.create']))])->assertCreated();

        $this->as(User::where('email', 'm@t.test')->first());
        $this->postJson("/api/walk-ins/$id/convert", ['name' => 'X', 'phone' => '01712345678'])->assertForbidden();

        $this->as($ownerB);
        $this->postJson("/api/walk-ins/$id/convert", ['name' => 'X', 'phone' => '01712345678'])->assertNotFound();
        $this->assertDatabaseHas('walk_ins', ['id' => $id]);
        $this->assertDatabaseCount('customers', 0);
    }
}
