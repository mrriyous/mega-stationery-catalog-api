<?php

namespace App\Support;

use App\Models\Category;
use App\Models\User;
use App\Models\Video;

final class SyncPayload
{
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'sort_order' => $category->sort_order,
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }

    public static function video(Video $video, ?User $viewer = null): array
    {
        $access = $viewer?->priceAccess();
        $canViewNormal = ! $viewer || $access['normal_price_access'];
        $canViewWholesale = ! $viewer || $access['wholesale_price_access'];

        return [
            'id' => $video->id,
            'category_id' => $video->category_id,
            'product_code' => $video->product_code,
            'product_name' => $video->product_name,
            'description' => $video->description,
            'normal_price' => $canViewNormal ? $video->normal_price : null,
            'wholesale_price' => $canViewWholesale ? $video->wholesale_price : null,
            'video_size_bytes' => $video->video_size_bytes,
            'video_file_available' => $video->video_file_available,
            'cover_file_available' => $video->cover_file_available,
            'video_extension' => pathinfo($video->video_path, PATHINFO_EXTENSION),
            'cover_extension' => $video->cover_path ? pathinfo($video->cover_path, PATHINFO_EXTENSION) : null,
            'video_url' => route('videos.download', $video, false),
            'cover_url' => $video->cover_path && $video->cover_file_available
                ? route('videos.cover', $video, false)
                : null,
            'updated_at' => $video->updated_at?->toISOString(),
        ];
    }
}
