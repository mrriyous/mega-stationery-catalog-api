<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_user_price_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/users', [
            'name' => 'Toko Mawar',
            'username' => 'mawar',
            'password' => 'secret123',
            'role' => 'user',
            'normal_price_access' => false,
            'wholesale_price_access' => true,
        ])->assertCreated()
            ->assertJsonPath('data.normal_price_access', false)
            ->assertJsonPath('data.wholesale_price_access', true);

        $userId = $created->json('data.id');
        $this->putJson("/api/users/{$userId}", [
            'name' => 'Toko Mawar',
            'username' => 'mawar',
            'role' => 'user',
            'normal_price_access' => true,
            'wholesale_price_access' => false,
        ])->assertOk()
            ->assertJsonPath('data.normal_price_access', true)
            ->assertJsonPath('data.wholesale_price_access', false);
    }

    public function test_me_returns_current_price_access_for_periodic_refresh(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'normal_price_access' => false,
            'wholesale_price_access' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.normal_price_access', false)
            ->assertJsonPath('user.wholesale_price_access', true);
    }

    public function test_regular_user_cannot_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses admin diperlukan.');
    }

    public function test_regular_user_must_have_exactly_one_price_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/users', [
            'name' => 'Tanpa Harga',
            'username' => 'tanpa-harga',
            'password' => 'secret123',
            'role' => 'user',
            'normal_price_access' => false,
            'wholesale_price_access' => false,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Pengguna harus memiliki tepat satu akses harga: Normal atau Grosir.');

        $this->postJson('/api/users', [
            'name' => 'Dua Harga',
            'username' => 'dua-harga',
            'password' => 'secret123',
            'role' => 'user',
            'normal_price_access' => true,
            'wholesale_price_access' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Pengguna harus memiliki tepat satu akses harga: Normal atau Grosir.');
    }

    public function test_sales_always_receives_both_prices_but_cannot_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson('/api/users', [
            'name' => 'Sales',
            'username' => 'sales',
            'password' => 'secret123',
            'role' => 'sales',
            'normal_price_access' => false,
            'wholesale_price_access' => false,
        ])->assertCreated()
            ->assertJsonPath('data.role', 'sales')
            ->assertJsonPath('data.normal_price_access', true)
            ->assertJsonPath('data.wholesale_price_access', true)
            ->assertJsonPath('data.offline_auth_version', 1);

        Sanctum::actingAs(User::findOrFail($response->json('data.id')));
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_password_change_increments_offline_auth_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sales = User::factory()->create(['role' => 'sales', 'offline_auth_version' => 4]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$sales->id}", [
            'name' => $sales->name,
            'username' => $sales->username,
            'password' => 'new-secret',
            'role' => 'sales',
            'normal_price_access' => true,
            'wholesale_price_access' => true,
        ])->assertOk()->assertJsonPath('data.offline_auth_version', 5);
    }
}
