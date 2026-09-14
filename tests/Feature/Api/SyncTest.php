<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\SyncChange;
use App\Models\User;
use App\Models\Video;
use App\Support\SyncPayload;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_device_receives_cursor_based_changes(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $category = Category::create(['name' => 'Produk', 'sort_order' => 0]);
        $change = SyncChange::create([
            'entity_type' => 'category',
            'entity_id' => $category->id,
            'action' => 'upsert',
            'payload' => SyncPayload::category($category),
        ]);

        $this->getJson('/api/sync?cursor=0')->assertOk()
            ->assertJsonPath('changes.0.cursor', $change->id)
            ->assertJsonPath('changes.0.payload.name', 'Produk')
            ->assertJsonPath('next_cursor', $change->id)
            ->assertJsonPath('has_more', false);
    }

    public function test_returns_401_when_sync_has_no_token(): void
    {
        $this->getJson('/api/sync?cursor=0')->assertUnauthorized();
    }

    public function test_returns_json_401_for_api_request_without_accept_header(): void
    {
        $this->get('/api/categories')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Tidak terautentikasi.');
    }

    public function test_more_than_200_changes_are_exposed_in_cursor_batches(): void
    {
        Sanctum::actingAs(User::factory()->create());
        for ($index = 1; $index <= 201; $index++) {
            SyncChange::create([
                'entity_type' => 'category',
                'entity_id' => $index,
                'action' => 'delete',
                'payload' => ['id' => $index],
            ]);
        }

        $firstBatch = $this->getJson('/api/sync?cursor=0')
            ->assertOk()
            ->assertJsonCount(200, 'changes')
            ->assertJsonPath('has_more', true);

        $cursor = $firstBatch->json('next_cursor');
        $this->getJson("/api/sync?cursor={$cursor}")
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.entity_id', 201)
            ->assertJsonPath('has_more', false);
    }

    public function test_only_latest_change_for_same_entity_is_returned(): void
    {
        Sanctum::actingAs(User::factory()->create());
        SyncChange::create([
            'entity_type' => 'video',
            'entity_id' => 42,
            'action' => 'upsert',
            'payload' => ['id' => 42, 'product_name' => 'Original'],
        ]);
        SyncChange::create([
            'entity_type' => 'video',
            'entity_id' => 42,
            'action' => 'upsert',
            'payload' => ['id' => 42, 'product_name' => 'Edited'],
        ]);
        $latest = SyncChange::create([
            'entity_type' => 'video',
            'entity_id' => 42,
            'action' => 'delete',
            'payload' => ['id' => 42],
        ]);

        $this->getJson('/api/sync?cursor=0')
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.cursor', $latest->id)
            ->assertJsonPath('changes.0.entity_type', 'video')
            ->assertJsonPath('changes.0.entity_id', 42)
            ->assertJsonPath('changes.0.action', 'delete')
            ->assertJsonPath('next_cursor', $latest->id)
            ->assertJsonPath('has_more', false);
    }

    public function test_category_history_is_preserved_before_compacted_video(): void
    {
        Sanctum::actingAs(User::factory()->create());
        SyncChange::create([
            'entity_type' => 'category',
            'entity_id' => 5,
            'action' => 'upsert',
            'payload' => ['id' => 5, 'name' => 'Original'],
        ]);
        SyncChange::create([
            'entity_type' => 'video',
            'entity_id' => 5,
            'action' => 'upsert',
            'payload' => ['id' => 5],
        ]);
        SyncChange::create([
            'entity_type' => 'category',
            'entity_id' => 5,
            'action' => 'upsert',
            'payload' => ['id' => 5, 'name' => 'Edited'],
        ]);

        $this->getJson('/api/sync?cursor=0')
            ->assertOk()
            ->assertJsonCount(3, 'changes')
            ->assertJsonPath('changes.0.entity_type', 'category')
            ->assertJsonPath('changes.1.entity_type', 'video')
            ->assertJsonPath('changes.2.entity_type', 'category');
    }

    public function test_bootstrap_returns_current_categories_before_paginated_videos(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $category = Category::factory()->create(['name' => 'Current category']);
        Video::factory()->count(51)->for($category)->create();
        $snapshotCursor = SyncChange::create([
            'entity_type' => 'video',
            'entity_id' => 999,
            'action' => 'delete',
            'payload' => ['id' => 999],
        ])->id;

        $first = $this->getJson('/api/sync/bootstrap?after_video_id=0')
            ->assertOk()
            ->assertJsonPath('snapshot_cursor', $snapshotCursor)
            ->assertJsonPath('categories.0.name', 'Current category')
            ->assertJsonCount(50, 'videos')
            ->assertJsonPath('has_more', true);

        $lastVideoId = $first->json('next_video_id');
        $this->getJson("/api/sync/bootstrap?after_video_id={$lastVideoId}")
            ->assertOk()
            ->assertJsonCount(0, 'categories')
            ->assertJsonCount(1, 'videos')
            ->assertJsonPath('has_more', false);
    }

    public function test_bootstrap_excludes_soft_deleted_categories_and_videos(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $keptCategory = Category::factory()->create(['name' => 'Aktif']);
        $removedCategory = Category::factory()->create(['name' => 'Arsip']);
        $keptVideo = Video::factory()->for($keptCategory)->create();
        $removedVideo = Video::factory()->for($keptCategory)->create();
        $removedCategory->delete();
        $removedVideo->delete();

        $this->getJson('/api/sync/bootstrap?after_video_id=0')
            ->assertOk()
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.name', 'Aktif')
            ->assertJsonCount(1, 'videos')
            ->assertJsonPath('videos.0.id', $keptVideo->id);
    }

    public function test_incremental_and_bootstrap_sync_scope_prices_to_current_user(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'normal_price_access' => true,
            'wholesale_price_access' => false,
        ]);
        Sanctum::actingAs($user);
        $video = Video::factory()->create([
            'normal_price' => 'VISIBLE-NORMAL',
            'wholesale_price' => 'SECRET-WHOLESALE',
        ]);
        $cursor = SyncChange::max('id') ?? 0;
        SyncChange::create([
            'entity_type' => 'video',
            'entity_id' => $video->id,
            'action' => 'upsert',
            'payload' => SyncPayload::video($video),
        ]);

        $this->getJson("/api/sync?cursor={$cursor}")
            ->assertOk()
            ->assertJsonPath('changes.0.payload.normal_price', 'VISIBLE-NORMAL')
            ->assertJsonPath('changes.0.payload.wholesale_price', null)
            ->assertJsonMissing(['wholesale_price' => 'SECRET-WHOLESALE']);
        $this->getJson('/api/sync/bootstrap?after_video_id=0')
            ->assertOk()
            ->assertJsonPath('videos.0.normal_price', 'VISIBLE-NORMAL')
            ->assertJsonPath('videos.0.wholesale_price', null);
    }

    public function test_sales_sync_receives_both_prices(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales']));
        $video = Video::factory()->create([
            'normal_price' => 'NORMAL',
            'wholesale_price' => 'WHOLESALE',
        ]);

        $this->getJson('/api/sync/bootstrap?after_video_id=0')
            ->assertOk()
            ->assertJsonPath('videos.0.id', $video->id)
            ->assertJsonPath('videos.0.normal_price', 'NORMAL')
            ->assertJsonPath('videos.0.wholesale_price', 'WHOLESALE');
    }
}
