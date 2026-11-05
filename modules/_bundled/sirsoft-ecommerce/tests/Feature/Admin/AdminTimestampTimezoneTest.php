<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 화면 API 의 생성·수정 시각이 보는 사람 시간대로 나가는지 검증 (#160)
 *
 * - 카테고리 상세(`CategoryResource`) — 카테고리 관리 상세 패널
 * - 상품 폼 데이터(`ProductService::getDetailForForm`) — 상품 수정·복사 화면
 */
class AdminTimestampTimezoneTest extends ModuleTestCase
{
    /**
     * 카테고리 상세의 생성·수정 시각은 보는 사람 시간대다 — UTC 16:00 은 서울 다음 날 01:00.
     *
     * @scenario surface=category_timestamps
     *
     * @effects admin_screen_timestamps_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_category_detail_timestamps_use_viewer_timezone(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.categories.read']);
        $category = Category::create([
            'name' => ['ko' => '시간대', 'en' => 'Timezone'],
            'slug' => 'tz-category',
            'path' => '',
            'depth' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        DB::table($category->getTable())->where('id', $category->id)->update([
            'path' => (string) $category->id,
            'created_at' => '2026-11-03 16:00:00',
            'updated_at' => '2026-11-04 16:30:00',
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson("/api/modules/sirsoft-ecommerce/admin/categories/{$category->id}");

        $response->assertOk();
        $this->assertSame('2026-11-04 01:00:00', $response->json('data.created_at'));
        $this->assertSame('2026-11-05 01:30:00', $response->json('data.updated_at'));
    }

    /**
     * 상품 폼 데이터(/form)의 생성·수정 시각은 보는 사람 시간대다.
     *
     * @scenario surface=product_form_timestamps
     *
     * @effects admin_screen_timestamps_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_product_form_timestamps_use_viewer_timezone(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $product = Product::factory()->create();
        DB::table($product->getTable())->where('id', $product->id)->update([
            'created_at' => Carbon::parse('2026-11-03 16:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-04 16:30:00', 'UTC'),
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}/form");

        $response->assertOk();
        $this->assertSame('2026-11-04 01:00:00', $response->json('data.created_at'));
        $this->assertSame('2026-11-05 01:30:00', $response->json('data.updated_at'));
    }
}
