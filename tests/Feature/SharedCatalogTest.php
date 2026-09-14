<?php

namespace Tests\Feature;

use App\Models\CatalogShareLink;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_a_24_hour_share_with_one_price(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'normal_price_access' => false,
            'wholesale_price_access' => true,
        ]);
        Sanctum::actingAs($user);
        $category = Category::factory()->create();
        $video = Video::factory()->for($category)->create([
            'product_name' => 'Pulpen Biru',
            'normal_price' => '10.000',
            'wholesale_price' => '8.000',
        ]);

        $response = $this->postJson('/api/catalog-shares', [
            'category_id' => $category->id,
            'search' => ' Pulpen ',
        ])->assertCreated();
        $token = Str::afterLast($response->json('url'), '/');

        $this->get("/s/{$token}")
            ->assertOk()
            ->assertSee('Mega Stationery Katalog')
            ->assertSee('Pulpen')
            ->assertSee('css/shared.css', false);
        $this->get("/s/{$token}/videos/{$video->id}")
            ->assertOk()
            ->assertSee('Pulpen Biru')
            ->assertSee('8.000')
            ->assertDontSee('10.000')
            ->assertSee('css/shared.css', false);

        $this->getJson("/s/{$token}/videos?category_id=999&search=anything")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('data.0.price', '8.000')
            ->assertJsonPath('data.0.cover_url', null)
            ->assertJsonPath('data.0.preview_url', route('shared-catalog.media', [$token, $video]).'#t=0.5')
            ->assertJsonMissing(['normal_price' => '10.000']);

        $share = CatalogShareLink::firstOrFail();
        $this->assertSame('wholesale', $share->price_type);
        $this->assertSame('Pulpen', $share->search);
        $this->assertTrue($share->expires_at->between(now()->addHours(23)->addMinutes(59), now()->addHours(24)->addMinute()));
    }

    public function test_share_scope_cannot_be_widened_by_query_parameters(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($user);
        $allowed = Category::factory()->create();
        $other = Category::factory()->create();
        $visible = Video::factory()->for($allowed)->create(['product_name' => 'Target Item']);
        Video::factory()->for($allowed)->create(['product_name' => 'Different Item']);
        Video::factory()->for($other)->create(['product_name' => 'Target Elsewhere']);
        $url = $this->postJson('/api/catalog-shares', [
            'category_id' => $allowed->id,
            'search' => 'Target',
        ])->json('url');
        $token = Str::afterLast($url, '/');

        $this->getJson("/s/{$token}/videos?category_id={$other->id}&search=")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_expired_or_deleted_owner_share_is_gone(): void
    {
        $user = User::factory()->create();
        $token = 'expired-token';
        CatalogShareLink::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'price_type' => 'normal',
            'expires_at' => now()->subSecond(),
        ]);

        $this->get("/s/{$token}")
            ->assertGone()
            ->assertSee('Link katalog tidak tersedia')
            ->assertSee('css/shared.css', false);
        $this->getJson("/s/{$token}/videos")->assertGone();
    }

    public function test_sales_must_choose_an_authorized_share_price(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        Sanctum::actingAs($sales);

        $this->postJson('/api/catalog-shares', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pilih harga Normal atau Grosir untuk link katalog.');

        $this->postJson('/api/catalog-shares', ['price_type' => 'wholesale'])
            ->assertCreated()
            ->assertJsonPath('price_type', 'wholesale');

        $normalUser = User::factory()->create([
            'role' => 'user',
            'normal_price_access' => true,
            'wholesale_price_access' => false,
        ]);
        Sanctum::actingAs($normalUser);
        $this->postJson('/api/catalog-shares', ['price_type' => 'wholesale'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Anda tidak memiliki akses ke harga yang dipilih.');
    }

    public function test_all_category_share_allows_category_navigation_but_keeps_search_scope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        Video::factory()->for($first)->create(['product_name' => 'Blue Pen']);
        $matching = Video::factory()->for($second)->create(['product_name' => 'Blue Book']);
        Video::factory()->for($second)->create(['product_name' => 'Red Book']);
        $url = $this->postJson('/api/catalog-shares', ['search' => 'Blue'])->json('url');
        $token = Str::afterLast($url, '/');

        $this->getJson("/s/{$token}/videos?category_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }
}
