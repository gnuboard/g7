<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductService SEO 동기화 플래그 영속화 테스트 (A26 / 다국어 전환)
 *
 * seo_sync_title / seo_sync_description 가 서버 SSoT 로 동작하여
 * ON 이면 name/description(다국어)으로 meta_*(다국어)를 채우고,
 * OFF 면 커스텀 다국어 입력을 보존하는지 검증합니다.
 * meta_title/meta_description 은 평문에서 다국어 JSON 으로 전환되어 언어별 SEO 분기를 지원합니다.
 */
class ProductServiceSeoSyncTest extends ModuleTestCase
{
    protected ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductService::class);
        config(['app.locale' => 'ko', 'app.fallback_locale' => 'ko']);
    }

    public function test_sync_title_on_mirrors_multilingual_name(): void
    {
        $product = Product::factory()->create([
            'name' => ['ko' => '동기화 상품', 'en' => 'Synced Product'],
            'meta_title' => ['ko' => '예전 커스텀 제목'],
        ]);

        $this->service->update($product, [
            'seo_sync_title' => true,
            'meta_title' => ['ko' => '무시될 입력'],
        ]);

        // ON: name 다국어 통째로 미러 (입력 무시) — 언어별 분기 보존
        $this->assertEquals(['ko' => '동기화 상품', 'en' => 'Synced Product'], $product->fresh()->meta_title);
        $this->assertTrue((bool) $product->fresh()->seo_sync_title);
    }

    public function test_sync_title_off_preserves_custom_multilingual_meta_title(): void
    {
        $product = Product::factory()->create([
            'name' => ['ko' => '상품명', 'en' => 'Name'],
        ]);

        $this->service->update($product, [
            'seo_sync_title' => false,
            'meta_title' => ['ko' => '직접 입력한 SEO 제목', 'en' => 'Custom SEO Title'],
        ]);

        // OFF: 커스텀 다국어 입력 보존
        $this->assertEquals(
            ['ko' => '직접 입력한 SEO 제목', 'en' => 'Custom SEO Title'],
            $product->fresh()->meta_title
        );
        $this->assertFalse((bool) $product->fresh()->seo_sync_title);
    }

    public function test_sync_title_off_with_empty_keeps_empty(): void
    {
        $product = Product::factory()->create([
            'name' => ['ko' => '상품명', 'en' => 'Name'],
            'meta_title' => ['ko' => '기존 값'],
        ]);

        $this->service->update($product, [
            'seo_sync_title' => false,
            'meta_title' => [],
        ]);

        $this->assertEmpty($product->fresh()->meta_title);
    }

    public function test_sync_flag_null_defaults_to_on(): void
    {
        $product = Product::factory()->create([
            'name' => ['ko' => '기본동기화', 'en' => 'Default Sync'],
            'meta_title' => ['ko' => '예전 값'],
            'seo_sync_title' => true,
        ]);

        // 플래그 미전송 → 기존 seo_sync_title(true) 폴백 → ON 처리
        $this->service->update($product, [
            'meta_title' => ['ko' => '무시될 입력'],
        ]);

        $this->assertEquals(['ko' => '기본동기화', 'en' => 'Default Sync'], $product->fresh()->meta_title);
    }

    public function test_sync_description_on_truncates_each_locale_to_160(): void
    {
        $longKo = str_repeat('가', 300);
        $longEn = str_repeat('a', 300);
        $product = Product::factory()->create([
            'description' => ['ko' => $longKo, 'en' => $longEn],
        ]);

        $this->service->update($product, [
            'seo_sync_description' => true,
            'meta_description' => ['ko' => '무시됨'],
        ]);

        $metaDescription = $product->fresh()->meta_description;
        // 로케일별 독립 160자 절단
        $this->assertEquals(160, mb_strlen($metaDescription['ko']));
        $this->assertEquals(160, mb_strlen($metaDescription['en']));
        $this->assertTrue((bool) $product->fresh()->seo_sync_description);
    }

    public function test_sync_description_on_strips_tags_per_locale(): void
    {
        $product = Product::factory()->create([
            'description' => ['ko' => '<p>한국어 <b>설명</b></p>', 'en' => '<p>English <i>desc</i></p>'],
        ]);

        $this->service->update($product, [
            'seo_sync_description' => true,
        ]);

        $metaDescription = $product->fresh()->meta_description;
        $this->assertEquals('한국어 설명', $metaDescription['ko']);
        $this->assertEquals('English desc', $metaDescription['en']);
    }

    public function test_sync_description_off_preserves_custom_multilingual(): void
    {
        $product = Product::factory()->create([
            'description' => ['ko' => '원본 설명', 'en' => 'desc'],
        ]);

        $this->service->update($product, [
            'seo_sync_description' => false,
            'meta_description' => ['ko' => '커스텀 설명', 'en' => 'Custom desc'],
        ]);

        $this->assertEquals(
            ['ko' => '커스텀 설명', 'en' => 'Custom desc'],
            $product->fresh()->meta_description
        );
        $this->assertFalse((bool) $product->fresh()->seo_sync_description);
    }

    public function test_update_off_then_reload_preserves_flag(): void
    {
        $product = Product::factory()->create([
            'name' => ['ko' => '상품', 'en' => 'Product'],
        ]);

        $this->service->update($product, [
            'seo_sync_title' => false,
            'meta_title' => ['ko' => '커스텀', 'en' => 'Custom'],
        ]);

        // getDetailForForm 재조회 시 의도 복원
        $detail = $this->service->getDetailForForm($product->id);
        $this->assertFalse($detail['seo_sync_title']);
        $this->assertEquals(['ko' => '커스텀', 'en' => 'Custom'], $detail['meta_title']);
    }

    public function test_create_with_sync_on_fills_multilingual_meta_title(): void
    {
        $category = new Category([
            'name' => ['ko' => '테스트 카테고리', 'en' => 'Test Category'],
            'slug' => 'seo-sync-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $category->path = 'temp';
        $category->save();
        $category->generatePath();
        $category->save();

        $product = $this->service->create([
            'name' => ['ko' => '신규 동기화', 'en' => 'New Synced'],
            'product_code' => 'SEO-CREATE-001',
            'category_ids' => [$category->id],
            'list_price' => 10000,
            'selling_price' => 8000,
            'stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'seo_sync_title' => true,
        ]);

        $this->assertEquals(['ko' => '신규 동기화', 'en' => 'New Synced'], $product->fresh()->meta_title);
    }
}
