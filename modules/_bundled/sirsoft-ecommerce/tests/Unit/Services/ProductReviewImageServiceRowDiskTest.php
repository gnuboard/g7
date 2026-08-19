<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\Storage\ModuleStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ProductReviewImageService 행 disk 기준 서빙/삭제 테스트
 *
 * 공개 자산 디스크 전환 이후에도 전환 이전 행(구 disk)의 서빙이 404 가 되지 않고,
 * 삭제가 그 행의 실제 저장 위치를 향하는지(혼재 운용 정합) 검증합니다.
 *
 * ProductReviewImageServiceTest 는 StorageInterface 를 Mock 으로 대체하므로
 * 실제 디스크 해석(withDisk) 회귀를 검출할 수 없어 별도 파일로 둡니다.
 */
class ProductReviewImageServiceRowDiskTest extends ModuleTestCase
{
    private ProductReviewImageService $service;

    private ProductReview $review;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        AssetStorage::flush();

        Auth::login($this->createUser());

        $product = Product::factory()->onSale()->create();
        $orderOption = OrderOption::factory()->create(['product_id' => $product->id]);

        $this->review = ProductReview::factory()->create([
            'product_id' => $product->id,
            'order_option_id' => $orderOption->id,
            'user_id' => Auth::id(),
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        $this->service = app(ProductReviewImageService::class);
    }

    protected function tearDown(): void
    {
        AssetStorage::flush();

        parent::tearDown();
    }

    /**
     * 지정 disk 에 파일과 리뷰 이미지 행을 함께 생성합니다.
     *
     * @param  string  $disk  행 disk
     * @param  string  $path  이미지 경로 (images/ 하위 상대 경로)
     * @return ProductReviewImage 생성된 행
     */
    private function makeRowOnDisk(string $disk, string $path): ProductReviewImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', $disk))->put('images', $path, 'image-bytes');

        return ProductReviewImage::create([
            'review_id' => $this->review->id,
            'original_filename' => 'review.jpg',
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
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'reviews/row-disk-test/cdn.jpg');
        $localRow = $this->makeRowOnDisk('modules', 'reviews/row-disk-test/local.jpg');

        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($cdnRow->hash));
        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($localRow->hash));
    }

    /**
     * @effects serve_and_delete_follow_row_disk
     */
    #[Test]
    public function delete_removes_file_on_row_disk(): void
    {
        $cdnRow = $this->makeRowOnDisk('fake_cdn', 'reviews/row-disk-test/del.jpg');
        $cdnDriver = new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn');
        $this->assertTrue($cdnDriver->exists('images', $cdnRow->path));

        $this->assertTrue($this->service->delete($cdnRow));

        $this->assertFalse($cdnDriver->exists('images', $cdnRow->path));
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
        $row = $this->makeRowOnDisk('fake_cdn', 'reviews/row-disk-test/orphan.jpg');
        $this->vanishDisk('fake_cdn');

        $this->assertNull($this->service->download($row->hash));
    }

    /**
     * 고아 disk 행의 삭제도 예외 없이 완료되어야 한다(리뷰 삭제 자체가 막히면 안 됨).
     *
     * @effects orphan_disk_falls_back_to_streaming
     */
    #[Test]
    public function delete_succeeds_when_row_disk_is_orphaned(): void
    {
        $row = $this->makeRowOnDisk('fake_cdn', 'reviews/row-disk-test/orphan-del.jpg');
        $this->vanishDisk('fake_cdn');

        $this->assertTrue($this->service->delete($row));
        $this->assertNull(ProductReviewImage::find($row->id));
    }

    /**
     * put 종단 — SP 카테고리 배선을 실제로 경유해 공개 자산 디스크 설정값이
     * 신규 행의 disk 컬럼에 기록되는지 단언합니다(배선 회귀 검출).
     *
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function upload_records_configured_public_asset_disk_on_row(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');

        $service = app(ProductReviewImageService::class);
        $image = $service->upload(
            UploadedFile::fake()->image('cdn-review.jpg', 40, 40),
            $this->review
        );

        $this->assertSame('fake_cdn', $image->disk);
        $this->assertTrue(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'))->exists('images', $image->path)
        );

        $this->assertTrue($service->delete($image));
    }

    /**
     * put 종단 — 공개 자산 디스크 미설정이면 신규 행이 기본 디스크로 기록(현행 보존).
     *
     * @effects mixed_rows_resolve_per_row_disk
     */
    #[Test]
    public function upload_records_base_disk_when_public_asset_disk_unset(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $service = app(ProductReviewImageService::class);
        $image = $service->upload(
            UploadedFile::fake()->image('local-review.jpg', 40, 40),
            $this->review
        );

        $this->assertSame('modules', $image->disk);

        $this->assertTrue($service->delete($image));
    }
}
