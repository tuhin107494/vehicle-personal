<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $name): User
    {
        $this->seed(PermissionSeeder::class);

        return app(ShopService::class)->create(['name' => $name], ['name' => "$name owner", 'email' => strtolower($name).'@t.test', 'password' => 'password'])->owner;
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function csv(string $body, string $name = 'customers.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function import(string $body)
    {
        return $this->postJson('/api/customers/import', ['file' => $this->csv($body)]);
    }

    public function test_valid_rows_are_created_in_the_shop_with_any_column_order(): void
    {
        $owner = $this->owner('A');
        $this->as($owner);

        $this->import("\xEF\xBB\xBFname,father_name,address,phone,alt_phone\nনিবারুন নেছা,ফিরোজ মিয়া,মৌলভীবাজার,01712696655,01726380064\n")
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.invalid', 0);

        $c = Customer::firstOrFail();
        $this->assertSame('নিবারুন নেছা', $c->name);
        $this->assertSame('01726380064', $c->alt_phone);
        $this->assertSame($owner->shop_id, $c->shop_id);
    }

    public function test_duplicates_are_skipped_silently_and_invalid_rows_reported(): void
    {
        $this->as($this->owner('A'));
        Customer::create(['name' => 'Old', 'phone' => '01800000001']);
        $deleted = Customer::create(['name' => 'Gone', 'phone' => '01800000002']);
        $deleted->delete();

        $res = $this->import("name,phone\nNew,01900000001\nSame in file,01900000001\nExisting,01800000001\nTrashed,01800000002\n,01900000003\nBad phone,abc\n")
            ->assertOk();

        $res->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped_duplicates', 3)->assertJsonPath('data.invalid', 2);
        $this->assertSame([6, 7], array_column($res->json('data.errors'), 'row'));

        // importing the same file again adds nothing
        $this->import("name,phone\nNew,01900000001\n")->assertJsonPath('data.created', 0);
    }

    public function test_a_phone_that_lost_its_leading_zero_is_restored(): void
    {
        $this->as($this->owner('A'));
        $this->import("name,phone\nRahim,1712696655\n")->assertJsonPath('data.created', 1);
        $this->assertSame('01712696655', Customer::first()->phone);
    }

    public function test_spreadsheet_formulas_are_defused(): void
    {
        $this->as($this->owner('A'));
        $this->import("name,phone,address\n=HYPERLINK(\"http://x\"),01900000001,@cmd\n")->assertJsonPath('data.created', 1);
        $c = Customer::first();
        $this->assertSame("'=HYPERLINK(\"http://x\")", $c->name);
        $this->assertSame("'@cmd", $c->address);
    }

    public function test_bad_files_are_refused(): void
    {
        $this->as($this->owner('A'));
        $this->import("name,mobile_x\nA,1\n")->assertStatus(409);
        $this->import("name,phone\n\xFF\xFE,0190\n")->assertStatus(409);
        $this->import("name,phone\nA\0B,01900000001\n")->assertStatus(409); // binary content
        $this->postJson('/api/customers/import', [])->assertStatus(422);
        $this->postJson('/api/customers/import', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertStatus(422);
    }

    public function test_it_needs_the_create_permission_and_never_touches_another_shop(): void
    {
        $a = $this->owner('A');
        $b = app(ShopService::class)->create(['name' => 'B'], ['name' => 'B owner', 'email' => 'b@t.test', 'password' => 'password'])->owner;

        $this->as($a);
        Customer::create(['name' => 'A customer', 'phone' => '01900000001']);

        // the same phone in another shop is a different customer
        $this->as($b);
        $this->import("name,phone\nB customer,01900000001\n")->assertJsonPath('data.created', 1);
        $this->assertSame(2, Customer::withoutGlobalScopes()->count());

        $staff = User::factory()->create(['role' => 'moderator', 'shop_id' => $b->shop_id]);
        $this->putJson('/api/roles/moderator/permissions', ['permissions' => ['parties.view']])->assertOk();
        $this->as($staff);
        $this->import("name,phone\nX,01900000009\n")->assertForbidden();
    }
}
