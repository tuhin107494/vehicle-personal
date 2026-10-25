<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $name): User
    {
        return app(ShopService::class)->create(['name' => $name], ['name' => "$name owner", 'email' => strtolower($name).'@t.test', 'password' => 'password'])->owner;
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_a_photo_is_added_replaced_and_removed(): void
    {
        Storage::fake('public');
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner('A');
        $this->as($owner);
        $customer = Customer::create(['name' => 'Rahim', 'phone' => '01800000001']);

        $first = $this->post("/api/customers/{$customer->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 100, 100)], ['Accept' => 'application/json'])->assertOk()->json('data.photo_url');
        $this->assertNotNull($first);
        $this->assertCount(1, Storage::disk('public')->allFiles('photos/customers'));

        // replacing keeps one file, not two
        $this->post("/api/customers/{$customer->id}/photo", ['photo' => UploadedFile::fake()->image('b.png', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, Storage::disk('public')->allFiles('photos/customers'));

        $this->getJson("/api/customers/{$customer->id}")->assertJsonPath('data.photo_url', fn ($u) => is_string($u))->assertJsonMissingPath('data.photo');

        $this->deleteJson("/api/customers/{$customer->id}/photo")->assertOk()->assertJsonPath('data.photo_url', null);
        $this->assertCount(0, Storage::disk('public')->allFiles('photos/customers'));
    }

    public function test_only_images_up_to_2mb_and_only_in_your_own_shop(): void
    {
        Storage::fake('public');
        $this->seed(PermissionSeeder::class);
        $a = $this->owner('A');
        $b = $this->owner('B');
        $this->as($a);
        $mine = Customer::create(['name' => 'Mine', 'phone' => '01800000002']);
        $this->as($b);
        $theirs = Customer::create(['name' => 'Theirs', 'phone' => '01800000003']);

        $this->as($a);
        $this->post("/api/customers/{$mine->id}/photo", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/customers/{$mine->id}/photo", ['photo' => UploadedFile::fake()->image('big.jpg')->size(3000)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/customers/{$theirs->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_user_sets_and_removes_their_own_photo(): void
    {
        Storage::fake('public');
        $this->seed(PermissionSeeder::class);
        $owner = $this->owner('A');
        $this->as($owner);

        $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('data.photo_url', fn ($u) => is_string($u));
        $this->deleteJson('/api/profile/photo')->assertOk();
        $this->getJson('/api/me')->assertJsonPath('data.photo_url', null);
    }
}
