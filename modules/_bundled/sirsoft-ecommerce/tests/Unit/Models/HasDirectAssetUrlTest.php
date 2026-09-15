<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * HasDirectAssetUrl(download_url 직접 URL/폴백) 단위 테스트
 *
 * 행 disk 가 직접 URL 지원 디스크(fake_cdn)면 CDN URL, 아니면 기존 API 경로 폴백을
 * 3개 이미지 모델(상품/카테고리/리뷰)에서 검증합니다. 혼재 운용(구 로컬 행 + 신규
 * 원격 행)과 훅 차단 폴백도 함께 고정합니다.
 */
class HasDirectAssetUrlTest extends ModuleTestCase
{
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

    protected function tearDown(): void
    {
        AssetStorage::flush();
        HookManager::resetAll();

        parent::tearDown();
    }

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
    }

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
    }

    /**
     * @scenario consumer=category, disk_setting=public, e2e=ecommerce_settings_field, hook=supply, override=module_override, row_state=new_remote_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
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
    }

    /**
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function mixed_rows_resolve_urls_per_row_disk(): void
    {
        // 혼재 운용 — 디스크 전환 이전 로컬 행과 이후 원격 행이 각자 disk 기준으로 해석
        $localRow = new ProductImage(['hash' => 'llllllllllll', 'disk' => 'modules', 'path' => 'products/P001/old.jpg']);
        $remoteRow = new ProductImage(['hash' => 'rrrrrrrrrrrr', 'disk' => 'fake_cdn', 'path' => 'products/P001/new.jpg']);

        $this->assertStringStartsWith('/api/modules/sirsoft-ecommerce/product-image/', $localRow->download_url);
        $this->assertStringStartsWith('https://cdn.test/assets/', $remoteRow->download_url);
    }

    /**
     * @scenario consumer=review, disk_setting=s3_without_url, e2e=ecommerce_settings_field, hook=unregistered, override=follow_core, row_state=new_remote_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
     */
    #[Test]
    public function blank_disk_or_path_falls_back_to_api_url(): void
    {
        $noDisk = new ProductImage(['hash' => 'aaaabbbbcccc', 'disk' => '', 'path' => 'products/P001/a.jpg']);
        $noPath = new ProductImage(['hash' => 'aaaabbbbcccc', 'disk' => 'fake_cdn', 'path' => '']);

        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc', $noDisk->download_url);
        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc', $noPath->download_url);
    }

    /**
     * @scenario consumer=category, disk_setting=none, e2e=ecommerce_settings_field, hook=block, override=follow_core, row_state=legacy_local_row
     *
     * @effects download_url_falls_back_to_api_path_when_direct_unavailable
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
    }
}
