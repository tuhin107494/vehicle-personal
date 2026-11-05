<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPermissionOverridesTest extends TestCase
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

    private function newUser(string $role, ?array $permissions = null): int
    {
        return $this->postJson('/api/users', array_filter([
            'name' => 'U', 'email' => $role.rand(100, 999).'@t.test', 'password' => 'password1', 'role' => $role, 'permissions' => $permissions,
        ], fn ($v) => $v !== null))->assertCreated()->json('data.id');
    }

    public function test_a_user_without_overrides_has_exactly_the_role_permissions(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);
        $role = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];

        $id = $this->newUser('moderator');

        $this->assertEqualsCanonicalizing($role, User::find($id)->permissionKeys());
    }

    public function test_the_owner_can_add_and_take_away_permissions_for_one_user(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);
        $default = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];
        $taken = $default[0];
        $extra = collect(['reports.view', 'settings.manage', 'sales.cancel'])->first(fn ($k) => ! in_array($k, $default, true));

        $wanted = array_values(array_merge(array_diff($default, [$taken]), [$extra]));
        $id = $this->newUser('moderator', $wanted);

        $keys = User::find($id)->permissionKeys();
        $this->assertContains($extra, $keys);
        $this->assertNotContains($taken, $keys);
        $this->assertDatabaseCount('user_permissions', 2);

        // another user of the same role is untouched
        $other = $this->newUser('moderator');
        $this->assertEqualsCanonicalizing($default, User::find($other)->permissionKeys());

        // saving the role's own list again clears the differences
        $this->putJson("/api/users/$id", ['name' => 'U', 'email' => User::find($id)->email, 'role' => 'moderator', 'permissions' => $default])->assertOk();
        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_a_taken_away_permission_is_refused_by_the_api_and_an_added_one_is_allowed(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);
        $default = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];
        $id = $this->newUser('moderator', array_values(array_diff($default, ['parties.view'])));

        $this->as(User::find($id));
        $this->getJson('/api/customers')->assertForbidden();
    }

    public function test_changing_the_role_without_a_list_drops_the_differences(): void
    {
        $owner = $this->makeShop('A');
        $this->as($owner);
        $default = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];
        $id = $this->newUser('moderator', array_values(array_diff($default, [$default[0]])));
        $this->assertDatabaseCount('user_permissions', 1);

        $this->putJson("/api/users/$id", ['name' => 'U', 'email' => User::find($id)->email, 'role' => 'admin'])->assertOk();

        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $this->as($this->makeShop('A'));

        $this->postJson('/api/users', ['name' => 'U', 'email' => 'u@t.test', 'password' => 'password1', 'role' => 'admin', 'permissions' => ['no.such.permission']])
            ->assertJsonValidationErrors('permissions.0');
        $this->assertDatabaseMissing('users', ['email' => 'u@t.test']);
    }

    public function test_only_the_owner_sets_permissions_and_not_across_shops(): void
    {
        $ownerA = $this->makeShop('A');
        $ownerB = $this->makeShop('B');
        $this->as($ownerA);
        $id = $this->newUser('admin');
        $email = User::find($id)->email;

        $this->as($ownerB);
        $this->putJson("/api/users/$id", ['name' => 'U', 'email' => $email, 'role' => 'admin', 'permissions' => ['parties.view']])->assertNotFound();
        $this->assertDatabaseCount('user_permissions', 0);

        $this->as(User::find($id));
        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@t.test', 'password' => 'password1', 'role' => 'admin', 'permissions' => ['parties.view']])->assertForbidden();
    }
}
