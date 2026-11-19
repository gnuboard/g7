<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 상품 상세 폼 직렬화 이미지 URL 호출측 회귀 테스트 (#100 URL 조립 단일화)
 *
 * accessor(download_url) 자체가 아니라 **호출측**(ProductService getDetailForForm)이
 * accessor 에 위임해 직렬화하는지 고정합니다 — 손조립 문자열로 회귀하면 행 disk
 * 기준 직접 URL(혼재 운용)이 화면에서 사라집니다.
 */
class ProductServiceDetailImageUrlTest extends ModuleTestCase
{
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
     * 상품 폼 직렬화가 행 disk 기준 download_url 과 항상 API 인 preview_url 을 함께 낸다.
     *
     * @effects mixed_rows_resolve_per_row_disk, preview_url_is_always_api_serving_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function detail_form_serializes_download_url_per_row_disk(): void
    {
        $product = Product::create([
            'product_code' => 'URLDELEG-'.Str::random(6),
            'name' => ['ko' => 'URL 위임 테스트 상품'],
            'selling_price' => 1000,
            'stock_quantity' => 1,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
        ]);

        $localImage = ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'old.jpg',
            'stored_filename' => 'old.jpg',
            'disk' => 'modules',
            'path' => 'products/'.$product->id.'/old.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'collection' => 'main',
            'is_thumbnail' => true,
            'sort_order' => 0,
        ]);
        $cdnImage = ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'new.jpg',
            'stored_filename' => 'new.jpg',
            'disk' => 'fake_cdn',
            'path' => 'products/'.$product->id.'/new.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 1,
        ]);

        $detail = app(ProductService::class)->getDetailForForm($product->id);

        $this->assertNotNull($detail);
        $byHash = collect($detail['images'])->keyBy('hash');

        // 혼재 운용 — 구 로컬 행은 API 폴백, 신규 원격 행은 직접 URL 로 직렬화
        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/product-image/'.$localImage->hash,
            $byHash[$localImage->hash]['download_url'],
        );
        $this->assertSame(
            'https://cdn.test/assets/sirsoft-ecommerce/images/products/'.$product->id.'/new.jpg',
            $byHash[$cdnImage->hash]['download_url'],
        );

        // 썸네일 기준 preview_url 은 두 행 모두 API 서빙 경로 — 폼의 미리보기·og 미리보기가 규격을 건다
        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/product-image/'.$localImage->hash,
            $byHash[$localImage->hash]['preview_url'],
        );
        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/product-image/'.$cdnImage->hash,
            $byHash[$cdnImage->hash]['preview_url'],
        );
    }
}
