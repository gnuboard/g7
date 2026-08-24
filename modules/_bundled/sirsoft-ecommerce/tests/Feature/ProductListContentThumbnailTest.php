<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Enums\PermissionType;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 상품 목록/상세/검색 표면의 설명 썸네일 폴백 테스트 (공개 이슈 #22 동종)
 *
 * 상품 이미지 없는 상품의 thumbnail_url 이 설명 캐시로 채워지는지,
 * 상품 이미지가 있으면 우선하는지, 비공개(display_status) 상품의 기존 노출
 * 범위를 폴백이 넓히지 않는지 API 경계에서 고정합니다.
 */
class ProductListContentThumbnailTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'ko']);

        // 비인증 공개 접근 (optional.sanctum + guest 권한)
        $productReadPerm = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'View Products'], 'type' => PermissionType::User]
        );
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest'], 'is_active' => true]
        );
        $guestRole->permissions()->syncWithoutDetaching([$productReadPerm->id]);
        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * @param  array  $attributes  덮어쓸 속성
     * @return Product 설명 이미지만 가진 상품
     */
    private function createContentImageProduct(array $attributes = []): Product
    {
        return Product::factory()->onSale()->create(array_merge([
            'description_mode' => 'html',
            'description' => ['ko' => '<p>설명</p><img src="/storage/products/desc-image.jpg">'],
        ], $attributes));
    }

    /**
     * 목록 응답에서 특정 상품 행을 찾습니다.
     *
     * @param  int  $productId  상품 ID
     * @return array<string, mixed>|null 목록 행
     */
    private function findListItem(int $productId): ?array
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products?per_page=50');
        $response->assertStatus(200);

        foreach ($response->json('data.data') ?? [] as $item) {
            if (($item['id'] ?? null) === $productId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @scenario image_source=content_internal_only, locale_content=default_locale
     *
     * @effects content_image_fills_product_thumbnail
     */
    #[Test]
    public function list_thumbnail_filled_from_description_without_product_image(): void
    {
        $product = $this->createContentImageProduct();

        $item = $this->findListItem($product->id);

        $this->assertNotNull($item, '목록에 상품이 있어야 합니다.');
        $this->assertSame('/storage/products/desc-image.jpg', $item['thumbnail_url']);
    }

    /**
     * @scenario image_source=product_image_only, locale_content=default_locale
     *
     * @effects product_image_takes_precedence
     */
    #[Test]
    public function product_image_takes_precedence_over_description_cache(): void
    {
        $product = $this->createContentImageProduct();

        $image = ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'main.jpg',
            'stored_filename' => 'main-'.uniqid().'.jpg',
            'disk' => 'modules',
            'path' => 'ecommerce/products/main.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'is_thumbnail' => true,
            'sort_order' => 0,
            'collection' => 'main',
        ]);

        $item = $this->findListItem($product->id);

        $this->assertNotNull($item);
        $this->assertSame(
            $image->download_url,
            $item['thumbnail_url'],
            '상품 이미지가 있으면 설명 캐시보다 우선해야 합니다.'
        );
    }

    /**
     * 상세(공개) 응답에도 같은 폴백이 반영되어야 합니다.
     *
     * @scenario image_source=both, locale_content=default_locale
     *
     * @effects detail_og_and_search_share_same_fallback
     */
    #[Test]
    public function public_detail_thumbnail_uses_same_fallback(): void
    {
        $product = $this->createContentImageProduct();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/products/'.$product->product_code);

        $response->assertStatus(200);
        $this->assertSame('/storage/products/desc-image.jpg', $response->json('data.thumbnail_url'));
    }

    /**
     * 검색 표면(searchByKeyword)의 상품도 폴백된 썸네일에 도달해야 합니다.
     *
     * @effects detail_og_and_search_share_same_fallback
     */
    #[Test]
    public function search_surface_reaches_fallback_thumbnail(): void
    {
        $product = $this->createContentImageProduct();

        // FULLTEXT 는 트랜잭션 내 신규 행을 보지 못한다 — 보조필드(product_code) 합집합
        // 매칭으로 같은 검색 쿼리 경로(관계 로드·게이트·컬럼 도달)를 검증한다
        $page = app(ProductService::class)->searchByKeyword($product->product_code);
        $found = collect($page->items())->firstWhere('id', $product->id);

        $this->assertNotNull($found, '검색 결과에 상품이 있어야 합니다.');
        $this->assertSame('/storage/products/desc-image.jpg', $found->getThumbnailUrl());
    }

    /**
     * 비공개 상품은 설명 캐시가 있어도 목록/검색에 노출되지 않아야 합니다 (게이트 불변).
     *
     * @scenario image_source=content_internal_only, locale_content=default_locale
     *
     * @effects hidden_product_stays_hidden
     */
    #[Test]
    public function hidden_product_is_not_exposed_by_fallback(): void
    {
        $hidden = $this->createContentImageProduct([
            'display_status' => ProductDisplayStatus::HIDDEN,
            'name' => ['ko' => '비공개폴백상품', 'en' => 'HiddenFallbackProduct'],
        ]);

        $this->assertNull($this->findListItem($hidden->id), '비공개 상품은 목록에 없어야 합니다.');

        $page = app(ProductService::class)->searchByKeyword($hidden->product_code);
        $this->assertNull(
            collect($page->items())->firstWhere('id', $hidden->id),
            '비공개 상품은 검색에도 없어야 합니다.'
        );
    }

    /**
     * @scenario image_source=content_external_only, locale_content=default_locale
     *
     * @effects external_only_description_yields_null
     */
    #[Test]
    public function external_only_description_yields_null_thumbnail(): void
    {
        $product = Product::factory()->onSale()->create([
            'description_mode' => 'html',
            'description' => ['ko' => '<img src="https://evil.example.org/x.jpg">'],
        ]);

        $item = $this->findListItem($product->id);

        $this->assertNotNull($item);
        $this->assertNull($item['thumbnail_url']);
    }

    /**
     * @scenario image_source=content_internal_only, locale_content=other_locale_only
     *
     * @effects other_locale_image_used_when_default_has_none
     */
    #[Test]
    public function other_locale_only_image_fills_thumbnail(): void
    {
        $product = Product::factory()->onSale()->create([
            'description_mode' => 'html',
            'description' => [
                'ko' => '<p>이미지 없는 한국어</p>',
                'en' => '<img src="/storage/products/en-image.jpg">',
            ],
        ]);

        $item = $this->findListItem($product->id);

        $this->assertNotNull($item);
        $this->assertSame('/storage/products/en-image.jpg', $item['thumbnail_url']);
    }
}
