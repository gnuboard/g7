<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\Storage\ModuleStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ProductImageService 행 disk 기준 서빙/삭제 테스트
 *
 * 디스크 전환 이후에도 전환 이전 행(구 disk)의 서빙이 404 가 되지 않고,
 * 삭제가 그 행의 실제 저장 위치를 향하는지(혼재 운용 정합) 검증합니다.
 */
class ProductImageServiceRowDiskTest extends ModuleTestCase
{
    private ProductImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        AssetStorage::flush();

        $this->service = app(ProductImageService::class);
    }

    protected function tearDown(): void
    {
        AssetStorage::flush();

        parent::tearDown();
    }

    /**
     * 지정 disk 에 파일과 이미지 행을 함께 생성합니다.
     *
     * @param  string  $disk  행 disk
     * @param  string  $path  이미지 경로
     * @return ProductImage 생성된 행
     */
    private function makeRowOnDisk(string $disk, string $path): ProductImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', $disk))->put('images', $path, 'image-bytes');

        return ProductImage::create([
            'temp_key' => 'row-disk-test',
            'original_filename' => 'a.jpg',
            'stored_filename' => basename($path),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * @effects serve_and_delete_follow_row_disk, mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function download_serves_row_from_its_recorded_disk(): void
    {
        // 혼재 운용 — 주입 스토리지 disk 와 다른 disk 의 행도 서빙 가능해야 한다
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'products/temp/row-disk-test/cdn.jpg');
        $localRow = $this->makeRowOnDisk('modules', 'products/temp/row-disk-test/local.jpg');

        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($cdnRow->hash));
        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($localRow->hash));
    }

    /**
     * @effects serve_and_delete_follow_row_disk
     */
    #[Test]
    public function delete_removes_file_on_row_disk(): void
    {
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'products/temp/row-disk-test/del.jpg');
        $cdnDriver = new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn');
        $this->assertTrue($cdnDriver->exists('images', $cdnRow->path));

        $this->assertTrue($this->service->delete($cdnRow->id));

        $this->assertFalse($cdnDriver->exists('images', $cdnRow->path));
    }

    /**
     * deleteByProductId 반환값 — 행이 CDN 디스크에만 있어도 행 disk 삭제 결과를
     * 반환해야 한다 (기본 디스크의 빈 디렉토리 결과로 오염 금지).
     *
     * @effects serve_and_delete_follow_row_disk
     */
    #[Test]
    public function delete_by_product_id_returns_row_disk_result_for_cdn_only_rows(): void
    {
        $product = Product::create([
            'product_code' => 'ROWDEL-'.Str::random(6),
            'name' => ['ko' => '행 disk 삭제 테스트'],
            'selling_price' => 1000,
            'stock_quantity' => 1,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
        ]);

        $path = "products/{$product->product_code}/cdn-only.jpg";
        $cdnDriver = new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn');
        $cdnDriver->put('images', $path, 'image-bytes');
        ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'cdn-only.jpg',
            'stored_filename' => 'cdn-only.jpg',
            'disk' => 'fake_cdn',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);

        $this->assertTrue($this->service->deleteByProductId($product->id));
        $this->assertFalse($cdnDriver->exists('images', $path));
    }

    /**
     * deleteByProductId — disk 컬럼이 빈 구 데이터 행은 주입 스토리지 소속으로
     * 정규화되어 함께 삭제된다.
     *
     * @effects serve_and_delete_follow_row_disk
     */
    #[Test]
    public function delete_by_product_id_normalizes_blank_disk_rows_to_base_storage(): void
    {
        $product = Product::create([
            'product_code' => 'ROWDEL-'.Str::random(6),
            'name' => ['ko' => '구 데이터 삭제 테스트'],
            'selling_price' => 1000,
            'stock_quantity' => 1,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
        ]);

        $path = "products/{$product->product_code}/legacy.jpg";
        $baseDriver = new ModuleStorageDriver('sirsoft-ecommerce', 'modules');
        $baseDriver->put('images', $path, 'image-bytes');
        ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'legacy.jpg',
            'stored_filename' => 'legacy.jpg',
            'disk' => '',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);

        $this->assertTrue($this->service->deleteByProductId($product->id));
        $this->assertFalse($baseDriver->exists('images', $path));
    }

    /**
     * put 종단 — SP 카테고리 배선(storageCategoryServices)을 실제로 경유해
     * 공개 자산 디스크 설정값이 신규 행의 disk 컬럼에 기록되는지 단언합니다.
     * (픽스처 disk 직접 주입이 아니라 실제 업로드 경로 — 배선 회귀 검출)
     *
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function upload_records_configured_public_asset_disk_on_row(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');

        // 컨테이너에서 새로 해석 — contextual binding 이 getStorageFor('images') 를 평가
        $service = app(ProductImageService::class);

        $image = $service->upload(
            UploadedFile::fake()->image('cdn-upload.jpg', 40, 40)
        );

        $this->assertSame('fake_cdn', $image->disk);
        $this->assertTrue(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'))->exists('images', $image->path)
        );

        // 뒷정리 — 업로드 파일 제거 (행 disk 기준 삭제)
        $this->assertTrue($service->delete($image->id));
    }

    /**
     * put 종단 — 공개 자산 디스크 미설정이면 신규 행이 기본 디스크로 기록 (현행 보존)
     *
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function upload_records_base_disk_when_public_asset_disk_unset(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $service = app(ProductImageService::class);

        $image = $service->upload(
            UploadedFile::fake()->image('local-upload.jpg', 40, 40)
        );

        $this->assertSame('modules', $image->disk);

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * 행 disk 를 제공하던 플러그인이 비활성화되어 config 에서 사라진 상태를 만듭니다.
     *
     * @param  string  $disk  사라지게 할 디스크명
     */
    private function vanishDisk(string $disk): void
    {
        Config::set("filesystems.disks.{$disk}", null);
        Storage::forgetDisk($disk);
        AssetStorage::flush();
    }

    /**
     * 고아 disk 행의 서빙은 500 이 아니라 스트리밍 폴백이어야 한다.
     *
     * @effects orphan_disk_falls_back_to_streaming
     */
    #[Test]
    public function download_falls_back_when_row_disk_is_orphaned(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'products/row-disk-test/orphan.jpg');
        $this->vanishDisk('fake_cdn');

        $this->assertNull($this->service->download($row->hash));
    }

    /**
     * 고아 disk 행의 삭제도 예외 없이 완료되어야 한다.
     *
     * @effects orphan_disk_falls_back_to_streaming
     */
    #[Test]
    public function delete_succeeds_when_row_disk_is_orphaned(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'products/row-disk-test/orphan-del.jpg');
        $this->vanishDisk('fake_cdn');

        $this->assertTrue($this->service->delete($row->id));
        $this->assertNull(ProductImage::find($row->id));
    }

    /**
     * 상품 삭제(이미지 일괄 삭제)도 고아 disk 행 때문에 막히면 안 된다.
     *
     * @effects orphan_disk_falls_back_to_streaming
     */
    #[Test]
    public function delete_by_product_id_succeeds_when_row_disk_is_orphaned(): void
    {
        $product = Product::factory()->create();
        $row = $this->makeRowOnDisk('fake_cdn', 'products/row-disk-test/orphan-bulk.jpg');
        $row->update(['product_id' => $product->id, 'temp_key' => null]);

        $this->vanishDisk('fake_cdn');

        $this->assertTrue($this->service->deleteByProductId($product->id));
    }
}
