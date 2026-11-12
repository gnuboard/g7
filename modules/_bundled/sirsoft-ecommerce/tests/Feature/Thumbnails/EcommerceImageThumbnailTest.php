<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Thumbnails;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use App\Seo\ExpressionEvaluator;
use App\Seo\SeoMetaResolver;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Listeners\ThumbnailSourcesListener;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Thumbnails\CategoryImageSourceProvider;
use Modules\Sirsoft\Ecommerce\Thumbnails\ClaimImageSourceProvider;
use Modules\Sirsoft\Ecommerce\Thumbnails\ProductImageSourceProvider;
use Modules\Sirsoft\Ecommerce\Thumbnails\ReviewImageSourceProvider;

/**
 * 이커머스 이미지 서빙 4곳의 썸네일 계약 (`?size=`·`?w=`)
 *
 * - 공개: 상품(`product-image`)·카테고리(`category-image`) — 행 확인 뒤 원본 응답 바로 앞에서 썸네일. 파생본은 공개
 *   썸네일 저장 위치, 그 위치가 공개 자산 디스크면 302.
 * - 게이트: 리뷰(`review-image` — 노출 상태·한시 서명)·클레임(`claim-image` — 한시 서명·본인·운영자) — 게이트를
 *   지난 뒤에만 썸네일, 비공개 썸네일 디스크·`private` 캐시. 서명은 `size`·`w` 를 빼고 검증한다.
 *
 * 파라미터가 없는 요청은 이전과 바이트·헤더가 같아야 한다. 라우터를 지나는 실제 HTTP 왕복으로 잰다.
 */
class EcommerceImageThumbnailTest extends ModuleTestCase
{
    private const API = '/api/modules/sirsoft-ecommerce/';

    /**
     * 원본·썸네일 디스크를 가짜 저장소로 바꾸고 규격 표를 기본값(defaults.json)으로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('thumbnails');

        config([
            'core.thumbnail.enabled' => true,
            'core.thumbnail.presets' => [],
            'core.thumbnail.disk_public' => 'thumbnails',
            'core.thumbnail.disk_gated' => 'thumbnails',
            'core.image.driver' => 'gd',
        ]);
        app(ThumbnailPresetRegistry::class)->flush();
    }

    // ------------------------------------------------------------------
    // 상품 이미지 (공개)
    // ------------------------------------------------------------------

    /**
     * 파라미터가 없으면 원본 그대로 — 썸네일 헤더도 파생본도 생기지 않는다.
     *
     * @scenario surface=product_image, thumbnail_query=none
     *
     * @effects ecommerce_product_image_without_size_returns_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_product_image_without_size_returns_the_original_unchanged(): void
    {
        $image = $this->makeProductImage();

        $response = $this->get(self::API.'product-image/'.$image->hash);

        $response->assertOk();
        $this->assertSame([1000, 800], $this->dimensions($response));
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($this->moduleDriver()->get('images', $image->path), $response->streamedContent());
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '파라미터 없는 요청이 파생본을 만들었다');
    }

    /**
     * `?size=sm` 은 160×160 파생본(첫 요청 miss, 다음 hit)이고 nosniff 를 싣는다. 키는 드라이버 전체 경로 기준이다.
     *
     * @scenario surface=product_image, thumbnail_query=size
     *
     * @effects ecommerce_product_image_size_returns_preset_dimensions, ecommerce_image_thumbnail_is_hardened
     *
     * @return void 반환값 없음
     */
    public function test_product_image_size_returns_derivative_with_preset_dimensions(): void
    {
        $image = $this->makeProductImage();

        $first = $this->get(self::API.'product-image/'.$image->hash.'?size=sm');

        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($first));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/jpeg', (string) $first->headers->get('Content-Type'));

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_', $files[0]);

        $second = $this->get(self::API.'product-image/'.$image->hash.'?size=sm');
        $second->assertOk();
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($second));
    }

    /**
     * `?w=300` 은 숫자 요청 대상 규격 중 가로 ≥ 300 인 최소(md, 가로 640)로 올려 맞춘다.
     *
     * @scenario surface=product_image, thumbnail_query=w
     *
     * @effects ecommerce_product_image_w_rounds_up_to_numeric_preset
     *
     * @return void 반환값 없음
     */
    public function test_product_image_numeric_width_rounds_up(): void
    {
        $image = $this->makeProductImage();

        $response = $this->get(self::API.'product-image/'.$image->hash.'?w=300');

        $response->assertOk();
        $this->assertSame([640, 512], $this->dimensions($response));
    }

    /**
     * 썸네일을 낼 수 없으면 원본에 사유를 붙인다 — 미정의 규격, 배열 키, 원본보다 큰 규격(확대 끔).
     *
     * @scenario surface=product_image, thumbnail_query=bypass
     *
     * @effects ecommerce_image_bypass_marks_original
     *
     * @return void 반환값 없음
     */
    public function test_product_image_unservable_request_returns_original_with_bypass_reason(): void
    {
        $image = $this->makeProductImage();

        foreach (['?size=nope' => 'unknown-preset', '?size[]=sm' => 'unknown-preset', '?size=xl' => 'smaller-than-preset'] as $query => $reason) {
            $response = $this->get(self::API.'product-image/'.$image->hash.$query);

            $response->assertOk();
            $this->assertSame('bypass:'.$reason, $response->headers->get(ThumbnailResponder::HEADER), $query);
            $this->assertSame([1000, 800], $this->dimensions($response), $query);
        }

        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 썸네일을 요청했는데 원본을 낼 때(미정의 규격·원본보다 큰 규격)는 운영 환경에서 그 원본의 유지 시간이 썸네일 응답과 같은 값
     * (코어 `cache.layout_ttl`)이다 — 상품·카테고리·리뷰 원본 주소의 1년으로 두면 운영자가 규격을 바꿔도 같은 `?size=` 주소가
     * 1년 동안 원본으로 남는다. `?size=` 가 없는 원본 주소의 1년, 교환·반품 증빙의 더 짧은 `private` 유지 시간은 그대로다.
     *
     * @scenario surface=product_image, surface=category_image, surface=review_image, surface=claim_image, thumbnail_query=bypass
     *
     * @effects ecommerce_image_bypass_original_uses_thumbnail_ttl
     *
     * @return void 반환값 없음
     */
    public function test_bypassed_original_uses_thumbnail_ttl_in_production(): void
    {
        config(['g7_settings.core.cache.layout_ttl' => 4321]);
        $urls = [
            'product' => self::API.'product-image/'.$this->makeProductImage()->hash,
            'category' => self::API.'category-image/'.$this->makeCategoryImage()->hash,
            'review' => self::API.'review-image/'.$this->makeReviewImage(ReviewStatus::VISIBLE)->hash,
        ];

        foreach ($urls as $surface => $url) {
            $plain = $this->inProduction(fn () => $this->get($url))->assertOk();
            $this->assertSame(ProductImageService::IMAGE_CACHE_MAX_AGE, $this->maxAge($plain), "{$surface} 원본 주소의 유지 시간이 바뀌었다");

            foreach (['?size=nope' => 'unknown-preset', '?size=xl' => 'smaller-than-preset'] as $query => $reason) {
                $response = $this->inProduction(fn () => $this->get($url.$query))->assertOk();
                $cacheControl = (string) $response->headers->get('Cache-Control');

                $this->assertSame('bypass:'.$reason, $response->headers->get(ThumbnailResponder::HEADER), "{$surface}{$query}");
                $this->assertSame(4321, $this->maxAge($response), "{$surface}{$query} 원본의 유지 시간이 썸네일과 다르다");
                $this->assertStringNotContainsString('immutable', $cacheControl, "{$surface}{$query}");
            }
        }

        $claim = $this->signedUrl('claim-image', $this->makeClaimImage($this->createUser())->hash, 30);
        $bypassed = $this->inProduction(fn () => $this->get($claim.'&size=nope'))->assertOk();
        $this->assertSame('bypass:unknown-preset', $bypassed->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame(ClaimAttachmentService::CACHE_MAX_AGE, $this->maxAge($bypassed), '증빙 원본의 더 짧은 유지 시간을 늘렸다');
        $this->assertStringContainsString('private', (string) $bypassed->headers->get('Cache-Control'));
    }

    /**
     * 운영 환경에서 공개 원본의 썸네일은 공유 캐시가 저장할 수 있는 `public` 이다 (게이트 원본의 `private` 와 반대축).
     *
     * @scenario surface=product_image, thumbnail_query=size_production_cache
     *
     * @effects ecommerce_public_image_thumbnail_cache_is_public
     *
     * @return void 반환값 없음
     */
    public function test_product_image_thumbnail_is_publicly_cacheable_in_production(): void
    {
        $image = $this->makeProductImage();

        $response = $this->inProduction(fn () => $this->get(self::API.'product-image/'.$image->hash.'?size=sm'));

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl, '공개 원본의 썸네일이 private 로 나갔다');
    }

    /**
     * 공개 썸네일 저장 위치가 공개 자산 디스크(public)면 302 로 파생본 직접 주소를 알려 준다.
     *
     * @scenario surface=product_image, thumbnail_query=size_public_disk
     *
     * @effects ecommerce_public_image_thumbnail_disk_redirects
     *
     * @return void 반환값 없음
     */
    public function test_product_image_public_asset_thumbnail_disk_redirects(): void
    {
        Storage::fake('public');
        config(['core.thumbnail.disk_public' => 'public']);
        $image = $this->makeProductImage();

        $response = $this->get(self::API.'product-image/'.$image->hash.'?size=sm');

        $response->assertStatus(302);
        $this->assertStringContainsString('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('public')->allFiles('thumbnails'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '공개 자산 디스크를 골랐는데 비공개 디스크에 썼다');
    }

    // ------------------------------------------------------------------
    // 카테고리 이미지 (공개)
    // ------------------------------------------------------------------

    /**
     * 카테고리 이미지도 무파라미터는 원본 그대로, `?size=sm` 은 160×160 파생본이다.
     *
     * @scenario surface=category_image, thumbnail_query=none, thumbnail_query=size
     *
     * @effects ecommerce_category_image_size_returns_preset_dimensions
     *
     * @return void 반환값 없음
     */
    public function test_category_image_serves_original_and_thumbnail(): void
    {
        $image = $this->makeCategoryImage();

        $original = $this->get(self::API.'category-image/'.$image->hash);
        $original->assertOk();
        $this->assertSame([1000, 800], $this->dimensions($original));
        $this->assertNull($original->headers->get(ThumbnailResponder::HEADER));

        $thumbnail = $this->get(self::API.'category-image/'.$image->hash.'?size=sm');
        $thumbnail->assertOk();
        $this->assertSame('miss', $thumbnail->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($thumbnail));
        $this->assertSame('nosniff', $thumbnail->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_', Storage::disk('thumbnails')->allFiles()[0]);
    }

    /**
     * 공개 썸네일 저장 위치가 공개 자산 디스크면 카테고리 이미지도 상품과 같이 직접 주소로 302 이고, 공유 캐시 대상이다.
     *
     * @scenario surface=category_image, thumbnail_query=size_public_disk
     *
     * @effects ecommerce_public_image_thumbnail_disk_redirects
     *
     * @return void 반환값 없음
     */
    public function test_category_image_public_asset_thumbnail_disk_redirects(): void
    {
        Storage::fake('public');
        config(['core.thumbnail.disk_public' => 'public']);
        $image = $this->makeCategoryImage();

        $response = $this->get(self::API.'category-image/'.$image->hash.'?size=sm');

        $response->assertStatus(302);
        $this->assertStringContainsString('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('public')->allFiles('thumbnails'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '공개 자산 디스크를 골랐는데 비공개 디스크에 썼다');
    }

    /**
     * 카테고리 이미지 서빙의 예상 밖 오류는 서버 오류(500)다 — 요청 탓(400)으로 내리면 원인이 클라이언트로 보인다.
     *
     * @scenario surface=category_image, thumbnail_query=none
     *
     * @effects ecommerce_category_image_unexpected_error_is_500
     *
     * @return void 반환값 없음
     */
    public function test_category_image_unexpected_error_is_a_server_error(): void
    {
        $service = \Mockery::mock(CategoryImageService::class);
        $service->shouldReceive('getFileInfo')->once()->andThrow(new \RuntimeException('storage is gone'));
        $this->app->instance(CategoryImageService::class, $service);

        $this->getJson(self::API.'category-image/abcdef123456')->assertStatus(500);
    }

    /**
     * 카테고리 공유 카드의 og:image 는 끝에 `size=og` 가 붙고, 그 주소가 실제로 og 규격 응답을 낸다. 모듈 선언
     * (`seoOgDefaults`)이 준 이미지를 코어 렌더가 그대로 받는 경로다.
     *
     * @scenario surface=category_image, thumbnail_query=og_card
     *
     * @effects ecommerce_category_og_image_uses_og_preset
     *
     * @return void 반환값 없음
     */
    public function test_category_og_image_renders_with_og_preset(): void
    {
        $this->registerListener();
        config(['app.url' => 'https://site.test']);
        $image = $this->makeCategoryImage();
        $url = $image->download_url;

        $og = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->seoOgDefaults('category', [
            'category' => ['data' => ['thumbnail_url' => $url, 'name' => '가방']],
        ]);

        $tags = (new SeoMetaResolver(new ExpressionEvaluator))->renderOgHtml($og);

        $this->assertStringContainsString('og:image" content="https://site.test'.$url.'?size=og"', $tags);
        $this->assertNotSame('bypass:unknown-preset', $this->get($url.'?size=og')->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 공개 자산 디스크(CDN) 카테고리 행의 공유 카드도 썸네일 기준 주소(`preview_url`, API)로 나가 `size=og` 가 붙는다.
     * 직접 URL(`download_url`)을 그대로 넘기면 og 규격이 걸리지 않고 원본이 나간다(#878).
     *
     * @scenario surface=category_image, thumbnail_query=og_card
     *
     * @effects ecommerce_category_og_image_uses_og_preset, ecommerce_direct_url_row_thumbnails_through_preview_url
     *
     * @return void 반환값 없음
     */
    public function test_category_og_image_for_cdn_row_uses_api_preview_url(): void
    {
        $this->registerListener();
        $this->useFakeCdnDisk();
        config(['app.url' => 'https://site.test']);
        $image = CategoryImage::create([
            'temp_key' => 'thumb-test',
            'original_filename' => 'thumb.jpg',
            'stored_filename' => 'cdn.jpg',
            'disk' => 'fake_cdn',
            'path' => 'category/2026/11/12/cdn.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'collection' => 'main',
            'sort_order' => 0,
        ]);

        $this->assertStringStartsWith('https://cdn.test/assets/', $image->download_url);
        $this->assertSame(self::API.'category-image/'.$image->hash, $image->preview_url);

        $og = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->seoOgDefaults('category', [
            'category' => ['data' => ['thumbnail_url' => $image->preview_url, 'name' => '가방']],
        ]);

        $tags = (new SeoMetaResolver(new ExpressionEvaluator))->renderOgHtml($og);

        $this->assertStringContainsString('og:image" content="https://site.test'.$image->preview_url.'?size=og"', $tags);
        $this->assertStringNotContainsString('cdn.test', $tags);
    }

    /**
     * 공개 자산 디스크(CDN) 행: 직접 URL(`download_url`)은 썸네일 대상이 아니고(같은 출처 `/api/` 패턴 밖),
     * 함께 내리는 `preview_url`(API)은 대상이다 — 화면이 `preview_url` 에 규격을 걸어야 썸네일을 받는다.
     *
     * @scenario surface=registration, thumbnail_query=registration
     *
     * @effects ecommerce_direct_url_row_thumbnails_through_preview_url
     *
     * @return void 반환값 없음
     */
    public function test_cdn_row_preview_url_is_thumbnailable_while_direct_url_is_not(): void
    {
        $this->registerListener();
        $this->useFakeCdnDisk();

        $rows = [
            'product-image' => new ProductImage(['hash' => 'aaaabbbbcccc', 'disk' => 'fake_cdn', 'path' => 'products/P1/a.jpg']),
            'category-image' => new CategoryImage(['hash' => 'ccccddddeeee', 'disk' => 'fake_cdn', 'path' => 'category/c.jpg']),
            'review-image' => new ProductReviewImage(['hash' => 'eeeeffff0000', 'disk' => 'fake_cdn', 'path' => 'reviews/1/r.jpg']),
        ];

        foreach ($rows as $segment => $row) {
            $this->assertStringStartsWith('https://cdn.test/assets/', $row->download_url, $segment);
            $this->assertFalse(ThumbnailUrl::isThumbnailable($row->download_url), $segment.' 직접 URL 이 썸네일 대상으로 잡혔다');
            $this->assertSame(self::API.$segment.'/'.$row->hash, $row->preview_url, $segment);
            $this->assertTrue(ThumbnailUrl::isThumbnailable($row->preview_url), $segment.' preview_url 이 썸네일 대상이 아니다');
        }
    }

    // ------------------------------------------------------------------
    // 리뷰 이미지 (게이트 — 노출 상태·한시 서명)
    // ------------------------------------------------------------------

    /**
     * 노출 리뷰 이미지의 썸네일은 게이트 원본이라 운영 환경에서도 `private` 이다. 무파라미터 원본은 그대로다.
     *
     * @scenario surface=review_image, thumbnail_query=none, thumbnail_query=size_production_cache
     *
     * @effects ecommerce_gated_image_thumbnail_cache_is_private, ecommerce_review_image_without_size_returns_original_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_visible_review_image_thumbnail_is_private(): void
    {
        $image = $this->makeReviewImage(ReviewStatus::VISIBLE);

        $original = $this->get(self::API.'review-image/'.$image->hash);
        $original->assertOk();
        $this->assertNull($original->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([1000, 800], $this->dimensions($original));

        $response = $this->inProduction(fn () => $this->get(self::API.'review-image/'.$image->hash.'?size=sm'));

        $response->assertOk();
        $this->assertSame([160, 160], $this->dimensions($response));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 숨김 리뷰 이미지는 서명 없이는 썸네일로도 열리지 않는다 — 원본과 같은 404, 파생본도 만들지 않는다.
     *
     * @scenario surface=review_image, thumbnail_query=size_gate_denied
     *
     * @effects ecommerce_gate_applies_to_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_hidden_review_image_thumbnail_is_denied_like_the_original(): void
    {
        $image = $this->makeReviewImage(ReviewStatus::HIDDEN);

        $this->get(self::API.'review-image/'.$image->hash)->assertNotFound();
        $this->get(self::API.'review-image/'.$image->hash.'?size=sm')->assertNotFound();
        $this->get(self::API.'review-image/'.$image->hash.'?w=300')->assertNotFound();

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트를 지나지 못한 요청이 파생본을 만들었다');
    }

    /**
     * 한시 서명 + `size` — 서명은 `size`·`w` 를 빼고 검증하므로 통과, 변조·만료는 원본과 같이 404,
     * `size[]` 는 서명은 유효하지만 규격을 정할 수 없어 원본 + bypass.
     *
     * @scenario surface=review_image, thumbnail_query=signed_size, thumbnail_query=bypass
     *
     * @effects ecommerce_signed_image_size_passes_signature, ecommerce_signed_image_tampered_or_expired_denied
     *
     * @return void 반환값 없음
     */
    public function test_hidden_review_image_signed_url_accepts_size_but_rejects_tampering(): void
    {
        $image = $this->makeReviewImage(ReviewStatus::HIDDEN);
        $signed = $this->signedUrl('review-image', $image->hash, 30);

        $thumbnail = $this->get($signed.'&size=sm');
        $thumbnail->assertOk();
        $this->assertSame('miss', $thumbnail->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([160, 160], $this->dimensions($thumbnail));

        $array = $this->get($signed.'&size[]=sm');
        $array->assertOk();
        $this->assertSame('bypass:unknown-preset', $array->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([1000, 800], $this->dimensions($array));

        $this->get(substr($signed, 0, -1).(str_ends_with($signed, 'a') ? 'b' : 'a').'&size=sm')->assertNotFound();
        $this->get($this->signedUrl('review-image', $image->hash, -1).'&size=sm')->assertNotFound();
    }

    /**
     * 리뷰 원본이 공개 자산 디스크에 있고 공개 썸네일 위치도 공개 자산 디스크여도, 리뷰 파생본은 비공개 썸네일
     * 디스크에만 저장하고 302 없이 직접 낸다(D25).
     *
     * @scenario surface=review_image, thumbnail_query=size_public_disk
     *
     * @effects ecommerce_review_thumbnail_stays_on_gated_disk
     *
     * @return void 반환값 없음
     */
    public function test_review_image_on_public_asset_disk_keeps_derivative_on_gated_disk(): void
    {
        Storage::fake('public');
        config(['core.thumbnail.disk_public' => 'public']);
        $image = $this->makeReviewImage(ReviewStatus::VISIBLE, disk: 'public');

        $response = $this->get(self::API.'review-image/'.$image->hash.'?size=sm');

        $response->assertOk();
        $this->assertSame([160, 160], $this->dimensions($response));
        $this->assertSame([], Storage::disk('public')->allFiles('thumbnails'), '게이트 원본의 파생본이 공개 자산 디스크에 올라갔다');

        $files = Storage::disk('thumbnails')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('thumbnails/public/sirsoft-ecommerce/images/'.$image->path.'/sm_', $files[0]);
    }

    // ------------------------------------------------------------------
    // 클레임 첨부 (게이트 — 한시 서명·본인·운영자)
    // ------------------------------------------------------------------

    /**
     * 무파라미터 원본은 그대로(`private, max-age=300`), 서명 + `size` 는 비공개 썸네일, 변조·만료는 404.
     *
     * @scenario surface=claim_image, thumbnail_query=none, thumbnail_query=signed_size, thumbnail_query=size_production_cache
     *
     * @effects ecommerce_claim_image_without_size_returns_original_unchanged, ecommerce_signed_image_size_passes_signature, ecommerce_signed_image_tampered_or_expired_denied, ecommerce_gated_image_thumbnail_cache_is_private
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_signed_url_serves_private_thumbnail(): void
    {
        $owner = $this->createUser();
        $image = $this->makeClaimImage($owner);
        $signed = $this->signedUrl('claim-image', $image->hash, 30);

        $original = $this->get($signed);
        $original->assertOk();
        $this->assertNull($original->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([1000, 800], $this->dimensions($original));
        $this->assertStringContainsString('private', (string) $original->headers->get('Cache-Control'));

        $thumbnail = $this->inProduction(fn () => $this->get($signed.'&size=sm'));
        $thumbnail->assertOk();
        $this->assertSame([160, 160], $this->dimensions($thumbnail));
        $this->assertSame('nosniff', $thumbnail->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $thumbnail->headers->get('Cache-Control'));
        $this->assertStringStartsWith('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_', Storage::disk('thumbnails')->allFiles()[0]);

        $this->get(substr($signed, 0, -1).(str_ends_with($signed, 'a') ? 'b' : 'a').'&size=sm')->assertNotFound();
        $this->get($this->signedUrl('claim-image', $image->hash, -1).'&size=sm')->assertNotFound();
    }

    /**
     * 클레임 첨부도 서명 주소에 배열 키(`size[]`)를 붙이면 서명은 통과하고 원본에 `bypass:unknown-preset` 을 붙여 낸다.
     *
     * @scenario surface=claim_image, thumbnail_query=bypass
     *
     * @effects ecommerce_image_bypass_marks_original
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_array_size_serves_original_with_bypass(): void
    {
        $image = $this->makeClaimImage($this->createUser());

        $array = $this->get($this->signedUrl('claim-image', $image->hash, 30).'&size[]=sm');

        $array->assertOk();
        $this->assertSame('bypass:unknown-preset', $array->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([1000, 800], $this->dimensions($array));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 서명 없이는 신청자 본인만 썸네일을 받고, 제3자 회원·비로그인은 원본과 같은 404 다(존재를 드러내지 않는다).
     *
     * @scenario surface=claim_image, thumbnail_query=size_gate_denied, thumbnail_query=size
     *
     * @effects ecommerce_gate_applies_to_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_thumbnail_follows_the_owner_gate(): void
    {
        $owner = $this->createUser();
        $stranger = $this->createUser();
        $image = $this->makeClaimImage($owner);
        $url = self::API.'claim-image/'.$image->hash;

        $this->get($url.'?size=sm')->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->get($url)->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->get($url.'?size=sm')->assertNotFound();
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '게이트를 지나지 못한 요청이 파생본을 만들었다');

        $mine = $this->actingAs($owner, 'sanctum')->get($url.'?size=sm');
        $mine->assertOk();
        $this->assertSame([160, 160], $this->dimensions($mine));
    }

    /**
     * 운영자(`claims.read`)는 서명 없이도 남의 클레임 첨부 썸네일을 받는다 — 게이트의 세 번째 갈래.
     *
     * @scenario surface=claim_image, thumbnail_query=size
     *
     * @effects ecommerce_claim_operator_receives_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_operator_receives_thumbnail_without_signature(): void
    {
        $image = $this->makeClaimImage($this->createUser());
        $operator = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $response = $this->actingAs($operator, 'sanctum')->get(self::API.'claim-image/'.$image->hash.'?size=sm');

        $response->assertOk();
        $this->assertSame([160, 160], $this->dimensions($response));
        $this->assertStringStartsWith('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_', Storage::disk('thumbnails')->allFiles()[0]);
    }

    /**
     * 클레임 첨부 썸네일은 원본(`private, max-age=300`)보다 오래 캐시되지 않는다 — 서명 주소가 만료되거나 신청자가
     * 첨부를 지운 뒤에도 브라우저가 썸네일만 하루 동안 들고 있으면 원본 통로의 짧은 캐시가 무의미해진다.
     *
     * @scenario surface=claim_image, thumbnail_query=size_production_cache
     *
     * @effects ecommerce_gated_thumbnail_not_cached_longer_than_original
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_thumbnail_is_not_cached_longer_than_the_original(): void
    {
        $image = $this->makeClaimImage($this->createUser());
        $signed = $this->signedUrl('claim-image', $image->hash, 30);

        $original = $this->inProduction(fn () => $this->get($signed));
        $thumbnail = $this->inProduction(fn () => $this->get($signed.'&size=sm'));

        $original->assertOk();
        $thumbnail->assertOk();
        $this->assertSame([160, 160], $this->dimensions($thumbnail));

        $originalMaxAge = $this->maxAge($original);
        $this->assertSame(ClaimAttachmentService::CACHE_MAX_AGE, $originalMaxAge);
        $this->assertLessThanOrEqual($originalMaxAge, $this->maxAge($thumbnail), '썸네일이 원본보다 오래 캐시된다');
        $this->assertStringContainsString('private', (string) $thumbnail->headers->get('Cache-Control'));
    }

    /**
     * 원본 파일만 사라진 행(원격 저장소에서 원본만 지워진 경우)은 `?size=` 도 무파라미터와 같은 404 다 — 이미 만든
     * 파생본이 남아 있어도 그것으로 200 을 내지 않는다. 네 서빙 지점 모두 원본 확인이 썸네일 호출보다 앞선다.
     *
     * @scenario surface=product_image, surface=category_image, surface=review_image, surface=claim_image, thumbnail_query=original_missing
     *
     * @effects ecommerce_thumbnail_requires_existing_original
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_is_not_served_when_the_original_file_is_gone(): void
    {
        $owner = $this->createUser();
        $claim = $this->makeClaimImage($owner);
        $cases = [
            'product' => [$this->makeProductImage(), fn ($image) => self::API.'product-image/'.$image->hash, '?'],
            'category' => [$this->makeCategoryImage(), fn ($image) => self::API.'category-image/'.$image->hash, '?'],
            'review' => [$this->makeReviewImage(ReviewStatus::VISIBLE), fn ($image) => self::API.'review-image/'.$image->hash, '?'],
            'claim' => [$claim, fn ($image) => $this->signedUrl('claim-image', $image->hash, 30), '&'],
        ];

        foreach ($cases as $label => [$image, $url, $glue]) {
            $address = $url($image);
            $this->get($address.$glue.'size=sm')->assertOk();

            // 저장소 계층(파생본 무효화)을 거치지 않고 원본만 지운다 — 원격 디스크에서 원본만 사라진 상황
            Storage::disk('modules')->delete('sirsoft-ecommerce/images/'.$image->path);
            $this->assertNotSame([], Storage::disk('thumbnails')->allFiles('thumbnails/modules/sirsoft-ecommerce/images/'.$image->path), $label);

            $this->assertSame(404, $this->get($address)->getStatusCode(), $label.' 무파라미터');
            $this->assertSame(404, $this->get($address.$glue.'size=sm')->getStatusCode(), $label.' size=sm');
            $this->assertSame(404, $this->get($address.$glue.'w=100')->getStatusCode(), $label.' w=100');
        }
    }

    /**
     * 서명은 `size`·`w` 만 빼고 검증한다 — 서명 + `w` 는 통과하지만, 그 밖의 파라미터(`&foo=1`)를 붙이면 서명이
     * 깨져 원본과 썸네일 모두 거부된다. 무시 목록이 지나치게 넓어지면(모든 쿼리 무시) 이 단언이 깨진다.
     *
     * @scenario surface=review_image, surface=claim_image, thumbnail_query=w, thumbnail_query=signed_foreign_param
     *
     * @effects ecommerce_signed_image_size_passes_signature, ecommerce_signed_image_foreign_param_denied
     *
     * @return void 반환값 없음
     */
    public function test_signed_url_ignores_only_thumbnail_parameters(): void
    {
        $cases = [
            'review-image' => $this->makeReviewImage(ReviewStatus::HIDDEN),
            'claim-image' => $this->makeClaimImage($this->createUser()),
        ];

        foreach ($cases as $segment => $image) {
            $signed = $this->signedUrl($segment, $image->hash, 30);

            $width = $this->get($signed.'&w=300');
            $width->assertOk();
            $this->assertSame([640, 512], $this->dimensions($width), $segment);

            $this->get($signed.'&foo=1')->assertNotFound();
            $this->get($signed.'&foo=1&size=sm')->assertNotFound();
            $this->get($signed.'&size=sm&foo=1')->assertNotFound();
        }
    }

    // ------------------------------------------------------------------
    // 리스너 — 서빙 경로 패턴·원본 제공자
    // ------------------------------------------------------------------

    /**
     * 리스너가 네 서빙 경로를 패턴에 더하고, 제공자 넷이 공개·게이트 분류와 경로 접두(임시 업로드 제외)로 원본을
     * 가르며, 이미지 행을 리포지토리로 세고 나눠 돌려준다.
     *
     * @scenario surface=registration, thumbnail_query=registration
     *
     * @effects ecommerce_thumbnail_listener_registers_url_patterns_and_providers
     *
     * @return void 반환값 없음
     */
    public function test_listener_registers_url_patterns_and_source_providers(): void
    {
        $this->registerListener();

        $product = $this->makeProductImage();
        $this->makeProductImage(temp: true);

        foreach (['product-image', 'category-image', 'review-image', 'claim-image'] as $segment) {
            $this->assertTrue(ThumbnailUrl::isThumbnailable(self::API.$segment.'/abcdef123456'), $segment);
        }
        $this->assertTrue(ThumbnailUrl::isThumbnailable($product->download_url), '화면이 받는 상품 이미지 주소가 패턴에 맞지 않는다');
        $this->assertSame(
            self::API.'review-image/abcdef123456?expires=1&signature=x&size=sm',
            ThumbnailUrl::for(self::API.'review-image/abcdef123456?expires=1&signature=x', 'sm')
        );
        $this->assertFalse(ThumbnailUrl::isThumbnailable(self::API.'products'));

        $providers = app(ThumbnailSourceProviders::class);
        $all = $providers->all();

        $this->assertInstanceOf(ProductImageSourceProvider::class, $all['sirsoft-ecommerce.product_image'] ?? null);
        $this->assertInstanceOf(CategoryImageSourceProvider::class, $all['sirsoft-ecommerce.category_image'] ?? null);
        $this->assertInstanceOf(ReviewImageSourceProvider::class, $all['sirsoft-ecommerce.review_image'] ?? null);
        $this->assertInstanceOf(ClaimImageSourceProvider::class, $all['sirsoft-ecommerce.claim_image'] ?? null);
        $this->assertFalse($all['sirsoft-ecommerce.product_image']->gated());
        $this->assertFalse($all['sirsoft-ecommerce.category_image']->gated());
        $this->assertTrue($all['sirsoft-ecommerce.review_image']->gated());
        $this->assertTrue($all['sirsoft-ecommerce.claim_image']->gated());

        $this->assertInstanceOf(ProductImageSourceProvider::class, $providers->classify('modules', 'sirsoft-ecommerce/images/products/P1/a.jpg'));
        $this->assertInstanceOf(ProductImageSourceProvider::class, $providers->classify('s3', 'sirsoft-ecommerce/images/products/P1/a.jpg'), '디스크를 옮긴 행을 분류하지 못했다');
        $this->assertInstanceOf(CategoryImageSourceProvider::class, $providers->classify('modules', 'sirsoft-ecommerce/images/category/2026/11/05/a.jpg'));
        $this->assertInstanceOf(ReviewImageSourceProvider::class, $providers->classify('public', 'sirsoft-ecommerce/images/reviews/3/a.jpg'));
        $this->assertInstanceOf(ClaimImageSourceProvider::class, $providers->classify('modules', 'sirsoft-ecommerce/images/claims/pending/7/a.jpg'));
        $this->assertNull($providers->classify('modules', 'sirsoft-ecommerce/images/products/temp/key/a.jpg'), '임시 업로드를 원본으로 분류했다');
        $this->assertNull($providers->classify('modules', 'sirsoft-ecommerce/exports/a.xlsx'));
        $this->assertNull($providers->classify('modules', 'other-module/images/products/a.jpg'));

        $productProvider = $all['sirsoft-ecommerce.product_image'];
        $this->assertSame(1, $productProvider->count(), '임시 업로드 행을 미리 만들기 대상으로 셌다');

        $chunk = $productProvider->chunk(null, 10);
        $this->assertNull($chunk['next']);
        $this->assertCount(1, $chunk['items']);
        $this->assertSame('modules', $chunk['items'][0]->disk);
        $this->assertSame('sirsoft-ecommerce/images/'.$product->path, $chunk['items'][0]->path);
        $this->assertFalse($chunk['items'][0]->gated);
        $this->assertSame(1000, $chunk['items'][0]->width);

        $review = $this->makeReviewImage(ReviewStatus::HIDDEN);
        $reviewChunk = $all['sirsoft-ecommerce.review_image']->chunk(null, 10);
        $this->assertSame('sirsoft-ecommerce/images/'.$review->path, $reviewChunk['items'][0]->path);
        $this->assertTrue($reviewChunk['items'][0]->gated);
    }

    /**
     * 제공자 `chunk` 는 id 커서로 이어 읽는다 — 한 장씩 읽어도 빠지거나 겹치는 행이 없고, 끝에서 `next` 가 null 이다.
     *
     * @scenario surface=registration, thumbnail_query=registration
     *
     * @effects ecommerce_source_provider_chunk_cursor_advances
     *
     * @return void 반환값 없음
     */
    public function test_source_provider_chunk_cursor_walks_every_row_once(): void
    {
        $this->registerListener();
        $first = $this->makeProductImage();
        $second = $this->makeProductImage();
        $provider = app(ThumbnailSourceProviders::class)->all()['sirsoft-ecommerce.product_image'];

        $page1 = $provider->chunk(null, 1);
        $this->assertSame((string) $first->id, $page1['next']);
        $this->assertSame(['sirsoft-ecommerce/images/'.$first->path], array_map(fn ($s) => $s->path, $page1['items']));

        $page2 = $provider->chunk($page1['next'], 1);
        $this->assertSame((string) $second->id, $page2['next']);
        $this->assertSame(['sirsoft-ecommerce/images/'.$second->path], array_map(fn ($s) => $s->path, $page2['items']));

        $page3 = $provider->chunk($page2['next'], 1);
        $this->assertSame([], $page3['items']);
        $this->assertNull($page3['next']);
    }

    /**
     * 제공자 목록을 모으거나 경로를 분류할 때는 서비스를 만들지 않는다 — 저장소 쓰기마다 분류가 돌므로, 세고 읽을 때
     * (count·chunk) 처음 해석한다.
     *
     * @scenario surface=registration, thumbnail_query=registration
     *
     * @effects ecommerce_source_providers_resolve_dependencies_lazily
     *
     * @return void 반환값 없음
     */
    public function test_source_providers_resolve_services_only_when_reading_rows(): void
    {
        $this->registerListener();
        $image = $this->makeClaimImage($this->createUser());
        $resolved = [];

        foreach ([ProductImageService::class, CategoryImageService::class, ProductReviewImageService::class, ClaimAttachmentService::class] as $class) {
            $this->app->resolving($class, function () use (&$resolved, $class) {
                $resolved[] = $class;
            });
        }

        $providers = app(ThumbnailSourceProviders::class);
        $all = $providers->all();
        $providers->classify('modules', 'sirsoft-ecommerce/images/claims/pending/7/a.jpg');
        $providers->classify('modules', 'sirsoft-ecommerce/images/products/P1/a.jpg');

        $this->assertSame([], $resolved, '제공자 목록·분류가 서비스를 만들었다');

        $chunk = $all['sirsoft-ecommerce.claim_image']->chunk(null, 10);

        $this->assertSame('sirsoft-ecommerce/images/'.$image->path, $chunk['items'][0]->path);
        $this->assertSame([ClaimAttachmentService::class], array_values(array_unique($resolved)));
    }

    // ------------------------------------------------------------------
    // 픽스처
    // ------------------------------------------------------------------

    /**
     * 가로 1000·세로 800 JPEG 바이트
     *
     * @return string JPEG 바이트
     */
    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(1000, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * 모듈 이미지 스토리지 드라이버
     *
     * @param  string  $disk  디스크
     * @return ModuleStorageDriver 드라이버
     */
    private function moduleDriver(string $disk = 'modules'): ModuleStorageDriver
    {
        return new ModuleStorageDriver('sirsoft-ecommerce', $disk);
    }

    /**
     * 상품 이미지 원본과 행을 만든다.
     *
     * @param  bool  $temp  상품 저장 전 임시 업로드인지
     * @return ProductImage 행
     */
    private function makeProductImage(bool $temp = false): ProductImage
    {
        $product = $temp ? null : Product::factory()->onSale()->create();
        $stored = Str::uuid().'.jpg';
        $path = $temp ? "products/temp/thumb-test/{$stored}" : "products/{$product->product_code}/{$stored}";
        $bytes = $this->jpegBytes();
        $this->moduleDriver()->put('images', $path, $bytes);

        return ProductImage::create([
            'product_id' => $product?->id,
            'temp_key' => $temp ? 'thumb-test' : null,
            'original_filename' => 'thumb.jpg',
            'stored_filename' => $stored,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($bytes),
            'width' => 1000,
            'height' => 800,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * 카테고리 이미지 원본과 행을 만든다.
     *
     * @return CategoryImage 행
     */
    private function makeCategoryImage(): CategoryImage
    {
        $stored = Str::uuid().'.jpg';
        $path = "category/2026/11/05/{$stored}";
        $bytes = $this->jpegBytes();
        $this->moduleDriver()->put('images', $path, $bytes);

        return CategoryImage::create([
            'temp_key' => 'thumb-test',
            'original_filename' => 'thumb.jpg',
            'stored_filename' => $stored,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($bytes),
            'width' => 1000,
            'height' => 800,
            'collection' => 'main',
            'sort_order' => 0,
        ]);
    }

    /**
     * 리뷰 이미지 원본과 행을 만든다.
     *
     * @param  ReviewStatus  $status  부모 리뷰 상태
     * @param  string  $disk  원본 디스크
     * @return ProductReviewImage 행
     */
    private function makeReviewImage(ReviewStatus $status, string $disk = 'modules'): ProductReviewImage
    {
        $review = ProductReview::factory()->create(['status' => $status->value]);
        $stored = Str::uuid().'.jpg';
        $path = "reviews/{$review->id}/{$stored}";
        $bytes = $this->jpegBytes();
        $this->moduleDriver($disk)->put('images', $path, $bytes);

        return ProductReviewImage::factory()->create([
            'review_id' => $review->id,
            'stored_filename' => $stored,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($bytes),
            'width' => 1000,
            'height' => 800,
        ]);
    }

    /**
     * 신청서 작성 중(미귀속) 클레임 첨부 원본과 행을 만든다 — 업로더가 소유자다.
     *
     * @param  User  $owner  업로더(신청자)
     * @return ClaimImage 행
     */
    private function makeClaimImage(User $owner): ClaimImage
    {
        $order = Order::factory()->confirmed()->forUser($owner)->create();
        $path = 'claims/'.ClaimImage::PENDING_CLAIMABLE_TYPE."/{$order->id}/".Str::uuid().'.jpg';
        $bytes = $this->jpegBytes();
        $this->moduleDriver()->put('images', $path, $bytes);

        return ClaimImage::factory()->create([
            'claimable_type' => ClaimImage::PENDING_CLAIMABLE_TYPE,
            'claimable_id' => $order->id,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
            'width' => 1000,
            'height' => 800,
            'created_by' => $owner->id,
        ]);
    }

    /**
     * 상대 한시 서명 주소 (응답 직렬화가 발급하는 것과 같은 모양)
     *
     * @param  string  $segment  서빙 경로 조각 (review-image·claim-image)
     * @param  string  $hash  이미지 해시
     * @param  int  $minutes  유효 시간(분, 음수면 만료)
     * @return string 서명 주소
     */
    private function signedUrl(string $segment, string $hash, int $minutes): string
    {
        return URL::temporarySignedRoute(
            "api.modules.sirsoft-ecommerce.{$segment}.download",
            now()->addMinutes($minutes),
            ['hash' => $hash],
            absolute: false
        );
    }

    /**
     * 응답 본문 이미지의 [가로, 세로]
     *
     * @param  TestResponse  $response  응답
     * @return array{0: int, 1: int} 크기
     */
    private function dimensions(TestResponse $response): array
    {
        $size = getimagesizefromstring($response->streamedContent());
        $this->assertIsArray($size, '응답 본문이 이미지가 아니다');

        return [$size[0], $size[1]];
    }

    /**
     * 응답 `Cache-Control` 의 max-age (초)
     *
     * @param  TestResponse  $response  응답
     * @return int 캐시 유지 상한(초)
     */
    private function maxAge(TestResponse $response): int
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertMatchesRegularExpression('/max-age=\d+/', $cacheControl, '응답에 max-age 가 없다');
        preg_match('/max-age=(\d+)/', $cacheControl, $m);

        return (int) $m[1];
    }

    /**
     * 운영 환경으로 바꿔 요청한다 (비운영은 원본·썸네일 모두 `no-cache` 라 공개·비공개 축이 갈리지 않는다).
     *
     * @param  callable(): TestResponse  $request  요청
     * @return TestResponse 응답
     */
    private function inProduction(callable $request): TestResponse
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            return $request();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    /**
     * 직접 URL 을 내는 가짜 공개 자산 디스크(`fake_cdn`)를 등록한다.
     *
     * @return void 반환값 없음
     */
    private function useFakeCdnDisk(): void
    {
        config(['filesystems.disks.fake_cdn' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]]);
        AssetStorage::flush();
        $this->beforeApplicationDestroyed(fn () => AssetStorage::flush());
    }

    /**
     * 리스너의 구독 훅을 실제 HookManager 에 건다 (tearDown 이 스냅샷으로 되돌린다).
     *
     * @return void 반환값 없음
     */
    private function registerListener(): void
    {
        $listener = new ThumbnailSourcesListener;

        foreach (ThumbnailSourcesListener::getSubscribedHooks() as $hook => $definition) {
            $this->assertSame('filter', $definition['type']);
            HookManager::addFilter($hook, [$listener, $definition['method']], $definition['priority']);
        }
    }

    /**
     * 서명으로 연 숨김 리뷰 원본은 공유 캐시에 남기지 않는다. 노출 리뷰 원본은 종전처럼 공유 캐시 대상이다.
     *
     * @scenario surface=review_image, thumbnail_query=signed_size
     *
     * @effects ecommerce_gated_original_cache_follows_gate
     *
     * @return void 반환값 없음
     */
    public function test_review_original_cache_follows_the_gate(): void
    {
        $visible = $this->makeReviewImage(ReviewStatus::VISIBLE);
        $hidden = $this->makeReviewImage(ReviewStatus::HIDDEN);
        $signed = $this->signedUrl('review-image', $hidden->hash, 30);

        $public = (string) $this->inProduction(fn () => $this->get(self::API.'review-image/'.$visible->hash))
            ->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $public);
        $this->assertStringNotContainsString('private', $public);

        $private = (string) $this->inProduction(fn () => $this->get($signed))
            ->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $private);
        $this->assertStringNotContainsString('public', $private);
    }
}
