<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Product 모델 content_thumbnail_url 캐시 계산 테스트 (공개 이슈 #22 동종)
 *
 * saving 이벤트가 다국어 설명(JSON)에서 첫 내부 이미지 URL 을 저장 시점에
 * 추출·캐시하는 계약을 고정합니다. 로케일 우선순위(기본 로케일 → 배열 순서),
 * text 모드 게이트, 필터 훅(sirsoft-ecommerce.product.filter_content_thumbnail)
 * 을 포함합니다.
 */
class ProductContentThumbnailTest extends ModuleTestCase
{
    private const FILTER_HOOK = 'sirsoft-ecommerce.product.filter_content_thumbnail';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'ko']);
    }

    protected function tearDown(): void
    {
        HookManager::clearFilter(self::FILTER_HOOK);
        parent::tearDown();
    }

    /**
     * @param  array  $attributes  덮어쓸 속성
     * @return Product 생성된 상품
     */
    private function createProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'description_mode' => 'html',
        ], $attributes));
    }

    /**
     * @scenario image_source=content_internal_only, locale_content=default_locale
     *
     * @effects content_image_fills_product_thumbnail
     */
    #[Test]
    public function saving_extracts_first_internal_image_from_default_locale(): void
    {
        $product = $this->createProduct([
            'description' => [
                'ko' => '<p>설명</p><img src="/storage/products/ko-first.jpg"><img src="/storage/products/ko-second.jpg">',
                'en' => '<img src="/storage/products/en-first.jpg">',
            ],
        ]);

        $this->assertSame('/storage/products/ko-first.jpg', $product->fresh()->content_thumbnail_url);
    }

    /**
     * 기본 로케일에 내부 이미지가 없으면 다른 로케일에서 찾아야 합니다.
     *
     * @scenario image_source=content_internal_only, locale_content=other_locale_only
     *
     * @effects other_locale_image_used_when_default_has_none
     */
    #[Test]
    public function falls_back_to_other_locale_when_default_has_no_internal_image(): void
    {
        $product = $this->createProduct([
            'description' => [
                'ko' => '<p>이미지 없는 한국어 설명</p><img src="https://evil.example.org/x.jpg">',
                'en' => '<img src="/storage/products/en-only.jpg">',
            ],
        ]);

        $this->assertSame('/storage/products/en-only.jpg', $product->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=content_external_only, locale_content=default_locale
     *
     * @effects external_only_description_yields_null
     */
    #[Test]
    public function external_only_description_yields_null(): void
    {
        $product = $this->createProduct([
            'description' => [
                'ko' => '<img src="https://evil.example.org/a.jpg"><img src="//cdn.example.org/b.jpg">',
            ],
        ]);

        $this->assertNull($product->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=none, locale_content=default_locale
     *
     * @effects external_only_description_yields_null
     */
    #[Test]
    public function description_without_image_yields_null(): void
    {
        $product = $this->createProduct([
            'description' => ['ko' => '<p>이미지 없는 설명</p>'],
        ]);

        $this->assertNull($product->fresh()->content_thumbnail_url);
    }

    /**
     * text 모드 설명은 리터럴 img 마크업이 있어도 캐시하지 않아야 합니다.
     *
     * @effects text_mode_description_never_caches
     */
    #[Test]
    public function text_mode_description_is_never_cached(): void
    {
        $product = $this->createProduct([
            'description_mode' => 'text',
            'description' => ['ko' => '텍스트 설명의 <img src="/storage/products/literal.jpg"> 마크업'],
        ]);

        $this->assertNull($product->fresh()->content_thumbnail_url);
    }

    /**
     * @effects recompute_on_description_change
     */
    #[Test]
    public function description_change_recomputes_cache(): void
    {
        $product = $this->createProduct([
            'description' => ['ko' => '<img src="/storage/products/old.jpg">'],
        ]);

        $product->update(['description' => ['ko' => '<img src="/storage/products/new.jpg">']]);

        $this->assertSame('/storage/products/new.jpg', $product->fresh()->content_thumbnail_url);
    }

    /**
     * @effects no_recompute_on_unrelated_update
     */
    #[Test]
    public function unrelated_update_does_not_recompute(): void
    {
        $product = $this->createProduct([
            'description' => ['ko' => '<img src="/storage/products/a.jpg">'],
        ]);

        DB::table('ecommerce_products')->where('id', $product->id)
            ->update(['content_thumbnail_url' => '/storage/products/manual.jpg']);

        $product->fresh()->update(['stock_quantity' => 7]);

        $this->assertSame('/storage/products/manual.jpg', $product->fresh()->content_thumbnail_url);
    }

    /**
     * @effects filter_hook_can_override_or_block
     */
    #[Test]
    public function filter_hook_can_override_or_block(): void
    {
        HookManager::addFilter(self::FILTER_HOOK, fn ($value) => null);

        $blocked = $this->createProduct([
            'description' => ['ko' => '<img src="/storage/products/internal.jpg">'],
        ]);

        $this->assertNull($blocked->fresh()->content_thumbnail_url);

        HookManager::clearFilter(self::FILTER_HOOK);
        HookManager::addFilter(self::FILTER_HOOK, fn ($value) => 'https://cdn.example.net/promoted.jpg');

        $promoted = $this->createProduct([
            'description' => ['ko' => '<p>설명</p>'],
        ]);

        $this->assertSame('https://cdn.example.net/promoted.jpg', $promoted->fresh()->content_thumbnail_url);
    }

    /**
     * 상품 이미지가 없으면 getThumbnailUrl 이 캐시로 폴백해야 합니다 (추가 쿼리 없이).
     *
     * @effects product_image_takes_precedence
     */
    #[Test]
    public function get_thumbnail_url_falls_back_to_cache_without_images(): void
    {
        $product = $this->createProduct([
            'description' => ['ko' => '<img src="/storage/products/cache.jpg">'],
        ]);

        $loaded = Product::with('images')->findOrFail($product->id);

        $this->assertSame('/storage/products/cache.jpg', $loaded->getThumbnailUrl());
    }
}
