<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Http\Resources\CartItemResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\CategoryResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductImageResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductReviewImageResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicCategoryDetailResource;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * 이미지 리소스의 두 주소 계약 (#878)
 *
 * 공개 자산 디스크(CDN) 행의 `download_url` 은 직접 주소라 썸네일 파이프(`thumbPreset`·`?size=`)가 걸리지 않는다.
 * 리소스는 원본(`download_url`)과 함께 썸네일 기준 주소 `preview_url`(항상 API 서빙 경로)을 내리고, 대표 이미지
 * 하나만 내리는 자리(`thumbnail_url`)는 API 주소를 쓴다 — 화면은 그 주소에 규격을 건다.
 *
 * 숨김 리뷰(서명 모드)는 원본·썸네일 모두 게이트가 있는 서명 API 주소다.
 */
class ImagePreviewUrlResourceTest extends ModuleTestCase
{
    private const CDN = 'https://cdn.test/assets/sirsoft-ecommerce/images/';

    private const API = '/api/modules/sirsoft-ecommerce/';

    /**
     * 가짜 공개 자산 디스크(fake_cdn)를 등록하고 드라이버 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        AssetStorage::flush();
    }

    /**
     * 드라이버 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetStorage::flush();

        parent::tearDown();
    }

    /**
     * 상품 이미지 리소스는 CDN 행의 원본을 직접 주소로, 썸네일 기준을 API 주소로 낸다.
     *
     * @scenario consumer=product, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function product_image_resource_emits_direct_download_and_api_preview(): void
    {
        $image = new ProductImage([
            'hash' => 'pppp00001111',
            'disk' => 'fake_cdn',
            'path' => 'products/P1/a.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
        ]);

        $data = (new ProductImageResource($image))->toArray(Request::create('/'));

        $this->assertSame(self::CDN.'products/P1/a.jpg', $data['download_url']);
        $this->assertSame(self::API.'product-image/pppp00001111', $data['preview_url']);
    }

    /**
     * 관리자 카테고리 리소스의 이미지 배열과 공개 카테고리 상세(og 생산자 `thumbnail_url` 포함).
     *
     * @scenario consumer=category, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects preview_url_is_always_api_serving_url, thumbnail_url_uses_api_preview
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function category_resources_emit_api_preview_and_api_thumbnail_url(): void
    {
        $category = Category::create([
            'name' => ['ko' => '미리보기 카테고리', 'en' => 'Preview Category'],
            'slug' => 'preview-cat-'.uniqid(),
            'is_active' => true,
            'depth' => 0,
            'path' => '',
            'sort_order' => 0,
        ]);
        $image = CategoryImage::create([
            'category_id' => $category->id,
            'original_filename' => 'cat.jpg',
            'stored_filename' => 'cat.jpg',
            'disk' => 'fake_cdn',
            'path' => 'category/preview/cat.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'collection' => 'main',
            'sort_order' => 0,
        ]);
        $category->load('images');
        $request = Request::create('/');

        $admin = (new CategoryResource($category))->toArray($request);
        $adminImage = collect($admin['images'])->first();

        $this->assertSame(self::CDN.'category/preview/cat.jpg', $adminImage['download_url']);
        $this->assertSame(self::API.'category-image/'.$image->hash, $adminImage['preview_url']);

        $public = (new PublicCategoryDetailResource($category))->toArray($request);
        $publicImage = collect($public['images'])->first();

        $this->assertSame(self::CDN.'category/preview/cat.jpg', $publicImage['download_url']);
        $this->assertSame(self::API.'category-image/'.$image->hash, $publicImage['preview_url']);
        // og:image 생산자 — 직접 URL 이면 `?size=og` 가 붙지 않는다
        $this->assertSame(self::API.'category-image/'.$image->hash, $public['thumbnail_url']);
    }

    /**
     * 노출 리뷰는 무서명 — 원본은 직접 주소, 미리보기는 API. 숨김 리뷰(서명 모드)는 둘 다 서명 API 주소다.
     *
     * @scenario consumer=review, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function review_image_resource_signs_both_urls_for_hidden_review(): void
    {
        $review = ProductReview::factory()->create(['status' => ReviewStatus::VISIBLE->value]);
        $image = ProductReviewImage::create([
            'review_id' => $review->id,
            'original_filename' => 'r.jpg',
            'stored_filename' => 'r.jpg',
            'disk' => 'fake_cdn',
            'path' => 'reviews/'.$review->id.'/r.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);
        $request = Request::create('/');

        [$visible] = ProductReviewImageResource::collectionFor([$image]);
        $visibleData = $visible->toArray($request);

        $this->assertSame(self::CDN.'reviews/'.$review->id.'/r.jpg', $visibleData['download_url']);
        $this->assertSame(self::API.'review-image/'.$image->hash, $visibleData['preview_url']);

        [$hidden] = ProductReviewImageResource::collectionFor([$image], signedDownload: true);
        $hiddenData = $hidden->toArray($request);

        $this->assertStringStartsWith(self::API.'review-image/'.$image->hash.'?', $hiddenData['download_url']);
        $this->assertStringContainsString('signature=', $hiddenData['download_url']);
        $this->assertSame($hiddenData['download_url'], $hiddenData['preview_url']);
    }

    /**
     * 장바구니·체크아웃 상품 정보의 `thumbnail_url` 은 API 주소 — 화면이 thumbPreset 을 건다.
     *
     * @scenario consumer=product, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects thumbnail_url_uses_api_preview
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function order_item_product_info_thumbnail_url_is_api_preview(): void
    {
        $product = new Product(['product_code' => 'PREVIEW-1', 'name' => ['ko' => '상품', 'en' => 'Product']]);
        $product->setRelation('images', collect([
            new ProductImage(['hash' => 'oooo00001111', 'disk' => 'fake_cdn', 'path' => 'products/P1/o.jpg', 'is_thumbnail' => true]),
        ]));

        $resource = new CartItemResource(null);
        $format = new ReflectionMethod($resource, 'formatProductInfo');
        $info = $format->invoke($resource, $product);

        $this->assertSame(self::API.'product-image/oooo00001111', $info['thumbnail_url']);
    }
}
