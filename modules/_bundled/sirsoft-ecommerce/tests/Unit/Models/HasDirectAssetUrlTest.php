<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Extension\HookManager;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Exports\Types\ProductExportType;
use Modules\Sirsoft\Ecommerce\Listeners\ThumbnailSourcesListener;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * HasDirectAssetUrl(download_url 직접 URL/폴백) 단위 테스트
 *
 * 행 disk 가 직접 URL 지원 디스크(fake_cdn)면 CDN URL, 아니면 기존 API 경로 폴백을
 * 3개 이미지 모델(상품/카테고리/리뷰)에서 검증합니다. 혼재 운용(구 로컬 행 + 신규
 * 원격 행)과 훅 차단 폴백도 함께 고정합니다.
 *
 * `preview_url`은 행 disk 와 무관하게 항상 API 서빙 경로다 — 직접 URL 은 썸네일 파이프
 * (`?size=`) 대상이 아니라서, 목록·미리보기·og·주문 스냅샷은 이 주소에 규격을 건다.
 */
class HasDirectAssetUrlTest extends ModuleTestCase
{
    /**
     * 가짜 공개 자산 디스크(fake_cdn — url 설정 디스크)를 등록하고 드라이버 캐시를 비운다.
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
     * 드라이버 캐시와 훅 등록을 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetStorage::flush();
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * 로컬 디스크 행은 직접 URL 이 없어 두 주소 모두 API 서빙 경로다.
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable, preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function product_image_falls_back_to_api_url_for_local_disk_row(): void
    {
        $image = new ProductImage([
            'hash' => 'aaaabbbbcccc',
            'disk' => 'modules',
            'path' => 'products/P001/a.jpg',
        ]);

        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc',
            $image->download_url
        );
        $this->assertSame($image->download_url, $image->preview_url);
    }

    /**
     * CDN 디스크 행의 download_url 은 직접 URL, preview_url 은 API 서빙 경로다.
     *
     * @effects preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function product_image_returns_direct_url_for_cdn_disk_row(): void
    {
        $image = new ProductImage([
            'hash' => 'aaaabbbbcccc',
            'disk' => 'fake_cdn',
            'path' => 'products/P001/a.jpg',
        ]);

        $this->assertSame(
            'https://cdn.test/assets/sirsoft-ecommerce/images/products/P001/a.jpg',
            $image->download_url
        );
        // 직접 URL 행이어도 썸네일 기준 주소는 API 서빙 경로
        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc', $image->preview_url);
    }

    /**
     * 카테고리(CDN 행)·리뷰(로컬 행) 이미지도 행 disk 기준으로 원본 주소를 고르고 preview_url 은 API 다.
     *
     * @scenario consumer=category, disk_setting=public, e2e=ecommerce_settings_field, hook=supply, override=module_override, row_state=new_remote_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable, preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function category_and_review_images_resolve_direct_url_and_fallback(): void
    {
        $cdnCategory = new CategoryImage([
            'hash' => 'ccc111222333',
            'disk' => 'fake_cdn',
            'path' => 'category/2026/01/01/c.jpg',
        ]);
        $localReview = new ProductReviewImage([
            'hash' => 'rrr111222333',
            'disk' => 'modules',
            'path' => 'reviews/1/r.jpg',
        ]);

        $this->assertSame(
            'https://cdn.test/assets/sirsoft-ecommerce/images/category/2026/01/01/c.jpg',
            $cdnCategory->download_url
        );
        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/review-image/rrr111222333',
            $localReview->download_url
        );
        $this->assertSame('/api/modules/sirsoft-ecommerce/category-image/ccc111222333', $cdnCategory->preview_url);
        $this->assertSame('/api/modules/sirsoft-ecommerce/review-image/rrr111222333', $localReview->preview_url);
    }

    /**
     * 혼재 운용 — 로컬 행과 CDN 행이 각자 disk 기준으로 원본 주소를 얻고 preview_url 은 둘 다 API 다.
     *
     * @effects mixed_rows_resolve_per_row_disk, preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function mixed_rows_resolve_urls_per_row_disk(): void
    {
        // 혼재 운용 — 디스크 전환 이전 로컬 행과 이후 원격 행이 각자 disk 기준으로 해석
        $localRow = new ProductImage(['hash' => 'llllllllllll', 'disk' => 'modules', 'path' => 'products/P001/old.jpg']);
        $remoteRow = new ProductImage(['hash' => 'rrrrrrrrrrrr', 'disk' => 'fake_cdn', 'path' => 'products/P001/new.jpg']);

        $this->assertStringStartsWith('/api/modules/sirsoft-ecommerce/product-image/', $localRow->download_url);
        $this->assertStringStartsWith('https://cdn.test/assets/', $remoteRow->download_url);
        // 썸네일 기준 주소는 행 disk 와 무관하게 둘 다 API 서빙 경로
        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/llllllllllll', $localRow->preview_url);
        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/rrrrrrrrrrrr', $remoteRow->preview_url);
    }

    /**
     * disk 나 path 가 비면 직접 URL 없이 API 서빙 경로로 돌아가고 두 주소가 같다.
     *
     * @scenario consumer=review, disk_setting=s3_without_url, e2e=ecommerce_settings_field, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function blank_disk_or_path_falls_back_to_api_url(): void
    {
        $noDisk = new ProductImage(['hash' => 'aaaabbbbcccc', 'disk' => '', 'path' => 'products/P001/a.jpg']);
        $noPath = new ProductImage(['hash' => 'aaaabbbbcccc', 'disk' => 'fake_cdn', 'path' => '']);

        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc', $noDisk->download_url);
        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc', $noPath->download_url);
        $this->assertSame($noDisk->download_url, $noDisk->preview_url);
        $this->assertSame($noPath->download_url, $noPath->preview_url);
    }

    /**
     * core.storage.filter_url 훅이 직접 URL 을 막으면 두 주소 모두 API 서빙 경로다.
     *
     * @scenario consumer=category, disk_setting=none, e2e=ecommerce_settings_field, hook=block, override=follow_core, row_state=legacy_local_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function hook_block_falls_back_to_api_url(): void
    {
        HookManager::addFilter('core.storage.filter_url', fn ($url) => '');

        $image = new ProductImage([
            'hash' => 'aaaabbbbcccc',
            'disk' => 'fake_cdn',
            'path' => 'products/P001/a.jpg',
        ]);

        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc',
            $image->download_url
        );
        // 훅이 직접 URL 을 막으면 두 주소가 같다 — 둘 다 API 서빙 경로
        $this->assertSame($image->download_url, $image->preview_url);
    }

    /**
     * 공개 자산 디스크(CDN) 행의 상품 대표 이미지: 목록·og·주문 스냅샷의 `thumbnail_url` 은 API(`preview_url`),
     * 내보내기 「대표 이미지 URL」은 원본(`download_url`, CDN)이다.
     *
     * @scenario consumer=product, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects thumbnail_url_uses_api_preview, preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function product_thumbnail_url_uses_api_preview_for_cdn_row(): void
    {
        $this->registerThumbnailPatterns();

        $product = new Product(['content_thumbnail_url' => null]);
        $product->setRelation('images', collect([
            new ProductImage(['hash' => 'tttttttttttt', 'disk' => 'fake_cdn', 'path' => 'products/P001/t.jpg', 'is_thumbnail' => true]),
        ]));

        $api = '/api/modules/sirsoft-ecommerce/product-image/tttttttttttt';

        $this->assertSame($api, $product->getThumbnailUrl());
        $this->assertSame($api, $product->toSnapshotArray()['thumbnail_url']);
        $this->assertTrue(ThumbnailUrl::isThumbnailable($product->getThumbnailUrl()));

        // S5 — 내보내기는 파일 자체가 필요하므로 원본(직접 URL)
        $this->assertSame(
            'https://cdn.test/assets/sirsoft-ecommerce/images/products/P001/t.jpg',
            $product->getThumbnailDownloadUrl()
        );
        $this->assertFalse(ThumbnailUrl::isThumbnailable($product->getThumbnailDownloadUrl()));
    }

    /**
     * 내보내기 「대표 이미지 URL」 계산식은 원본 주소(`getThumbnailDownloadUrl`)를 쓴다 — 썸네일용 서빙 주소
     * (`getThumbnailUrl`)로 바뀌면 스프레드시트가 원본 대신 API 주소를 받는다(S5).
     *
     * @effects thumbnail_url_uses_api_preview
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function product_export_main_image_url_keeps_original_download_url(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ProductExportType::class))->getFileName());

        $this->assertMatchesRegularExpression(
            "/'product\\.thumbnail_url'.{0,400}getThumbnailDownloadUrl\\(\\)/s",
            $source
        );
        $this->assertStringNotContainsString('->getThumbnailUrl()', $source);
    }

    /**
     * 모듈 리스너의 썸네일 경로 패턴을 코어 목록에 건다 (tearDown 의 HookManager::resetAll 이 되돌린다).
     *
     * @return void 반환값 없음
     */
    private function registerThumbnailPatterns(): void
    {
        $listener = new ThumbnailSourcesListener;

        foreach (ThumbnailSourcesListener::getSubscribedHooks() as $hook => $definition) {
            HookManager::addFilter($hook, [$listener, $definition['method']], $definition['priority']);
        }
    }
}
