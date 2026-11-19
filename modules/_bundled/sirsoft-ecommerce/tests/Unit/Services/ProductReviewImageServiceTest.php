<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Repositories\ProductReviewImageRepository;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductReviewImageService 단위 테스트
 *
 * 이미지 업로드, 삭제, 다운로드 비즈니스 로직을 검증합니다.
 * StorageInterface는 Mock으로 대체합니다.
 */
class ProductReviewImageServiceTest extends ModuleTestCase
{
    private ProductReviewImageService $service;

    /** @var MockInterface&StorageInterface */
    private $storage;

    /** @var MockInterface&EcommerceSettingsService */
    private $settingsService;

    private ProductReview $review;

    private User $user;

    /**
     * 모의 스토리지와 모의 설정 서비스로 ProductReviewImageService 를 만들고, 로그인한 회원의 리뷰 한 건을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = Mockery::mock(StorageInterface::class);
        $this->settingsService = Mockery::mock(EcommerceSettingsService::class);

        $this->service = new ProductReviewImageService(
            $this->storage,
            $this->settingsService,
            new ProductReviewImageRepository(new ProductReviewImage)
        );

        $this->user = $this->createUser();
        Auth::login($this->user);

        $product = Product::factory()->onSale()->create();
        $orderOption = OrderOption::factory()->create(['product_id' => $product->id]);

        $this->review = ProductReview::factory()->create([
            'product_id' => $product->id,
            'order_option_id' => $orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);
    }

    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ========================================
    // upload() 테스트
    // ========================================

    /**
     * 업로드가 리뷰에 묶인 이미지 행을 만들고 원본 파일명, MIME, 해시, 업로더를 기록하는지 확인
     *
     * @scenario case=upload
     *
     * @effects review_image_upload_creates_record_with_file_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_upload_creates_image_record(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review_settings.max_images', Mockery::any())
            ->andReturn(5);

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->once()
            ->andReturn('local');

        $image = $this->service->upload($file, $this->review);

        $this->assertInstanceOf(ProductReviewImage::class, $image);
        $this->assertEquals($this->review->id, $image->review_id);
        $this->assertEquals('test.jpg', $image->original_filename);
        $this->assertEquals('image/jpeg', $image->mime_type);
        $this->assertNotNull($image->hash);
        $this->assertEquals($this->user->id, $image->created_by);
    }

    /**
     * 이미 최대 장수만큼 이미지가 있으면 업로드가 RuntimeException 으로 거절되는지 확인
     *
     * @scenario case=upload_over_limit
     *
     * @effects review_image_upload_rejected_over_max_images
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_upload_throws_when_max_images_exceeded(): void
    {
        // 이미 5개의 이미지가 있는 상태
        ProductReviewImage::factory()->count(5)->create([
            'review_id' => $this->review->id,
        ]);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review_settings.max_images', Mockery::any())
            ->andReturn(5);

        $file = UploadedFile::fake()->image('extra.jpg');

        $this->expectException(\RuntimeException::class);

        $this->service->upload($file, $this->review);
    }

    /**
     * 최대 장수 설정이 0 이면 첫 업로드부터 거절되는지 확인
     *
     * @scenario case=upload_limit_zero
     *
     * @effects max_images_zero_blocks_first_upload
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_upload_blocks_first_image_when_max_images_zero(): void
    {
        // max_images=0 → 첫 업로드부터 차단 (이미지 첨부 완전 불가 정책)
        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review_settings.max_images', Mockery::any())
            ->andReturn(0);

        $file = UploadedFile::fake()->image('blocked.jpg');

        $this->expectException(\RuntimeException::class);

        $this->service->upload($file, $this->review);
    }

    /**
     * 첫 이미지가 대표 이미지로 지정되고 정렬 순서 1 을 받는지 확인
     *
     * @scenario case=upload_first_image
     *
     * @effects review_image_first_upload_becomes_thumbnail
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_upload_sets_first_image_as_thumbnail(): void
    {
        $file = UploadedFile::fake()->image('first.jpg', 800, 600);

        $this->settingsService->shouldReceive('getSetting')->andReturn(5);
        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');

        $image = $this->service->upload($file, $this->review);

        $this->assertTrue($image->is_thumbnail);
        $this->assertEquals(1, $image->sort_order);
    }

    /**
     * 기존 이미지가 있으면 다음 정렬 순서를 받고 대표 이미지로 지정되지 않는지 확인
     *
     * @scenario case=upload_next_image
     *
     * @effects review_image_upload_takes_next_sort_order
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_upload_increments_sort_order(): void
    {
        ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'sort_order' => 2,
        ]);

        $file = UploadedFile::fake()->image('second.jpg', 400, 300);

        $this->settingsService->shouldReceive('getSetting')->andReturn(5);
        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');

        $image = $this->service->upload($file, $this->review);

        $this->assertEquals(3, $image->sort_order);
        $this->assertFalse($image->is_thumbnail);
    }

    /**
     * 업로드가 before_upload, filter_upload_file, after_upload 훅을 발행하고 필터가 돌려준 파일로 저장하는지 확인
     *
     * @scenario case=upload_hooks
     *
     * @effects review_image_upload_fires_before_filter_after_hooks, review_image_filter_upload_file_hook_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_upload_fires_hooks(): void
    {
        // Arrange
        $beforeUploadFired = false;
        $afterUploadFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-ecommerce.review-image.before_upload', function () use (&$beforeUploadFired) {
            $beforeUploadFired = true;
        });

        HookManager::addFilter('sirsoft-ecommerce.review-image.filter_upload_file', function ($file) use (&$filterApplied) {
            $filterApplied = true;

            // 반환 파일이 실제로 소비되는지 검증하기 위해 다른 이름/확장자의 파일을 반환
            return UploadedFile::fake()->image('filtered.webp');
        });

        HookManager::addAction('sirsoft-ecommerce.review-image.after_upload', function () use (&$afterUploadFired) {
            $afterUploadFired = true;
        });

        $file = UploadedFile::fake()->image('original.jpg');

        $this->settingsService->shouldReceive('getSetting')->andReturn(5);
        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');

        // Act
        $image = $this->service->upload($file, $this->review);

        // Assert
        $this->assertTrue($beforeUploadFired, 'before_upload hook should be fired');
        $this->assertTrue($filterApplied, 'filter_upload_file hook should be applied');
        $this->assertTrue($afterUploadFired, 'after_upload hook should be fired');
        // 필터가 반환한 파일이 저장 파일명의 근거가 되어야 한다 (반환값 소비 증명)
        $this->assertStringEndsWith('.webp', $image->stored_filename, 'filter return value should be consumed');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.review-image.before_upload');
        HookManager::clearFilter('sirsoft-ecommerce.review-image.filter_upload_file');
        HookManager::clearAction('sirsoft-ecommerce.review-image.after_upload');
    }

    // ========================================
    // delete() 테스트
    // ========================================

    /**
     * 삭제가 저장 파일을 지우고 이미지 행을 소프트 삭제하는지 확인
     *
     * @scenario case=delete
     *
     * @effects review_image_delete_removes_file_and_soft_deletes_row
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_delete_removes_file_and_record(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'path' => 'reviews/1/test.jpg',
        ]);

        // 삭제는 행 disk 기준으로 스토리지를 해석한다 (혼재 운용, 공개#100)
        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        $this->storage
            ->shouldReceive('exists')
            ->with('images', 'reviews/1/test.jpg')
            ->once()
            ->andReturn(true);

        $this->storage
            ->shouldReceive('delete')
            ->with('images', 'reviews/1/test.jpg')
            ->once()
            ->andReturn(true);

        $result = $this->service->delete($image);

        $this->assertTrue($result);
        $this->assertSoftDeleted('ecommerce_product_review_images', ['id' => $image->id]);
    }

    /**
     * 저장 파일이 없으면 스토리지 삭제를 호출하지 않고 삭제가 성공하는지 확인
     *
     * @scenario case=delete_file_missing
     *
     * @effects review_image_delete_skips_storage_when_file_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_delete_skips_file_removal_when_not_exists(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'path' => 'reviews/1/missing.jpg',
        ]);

        // 삭제는 행 disk 기준으로 스토리지를 해석한다 (혼재 운용, 공개#100)
        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        $this->storage
            ->shouldReceive('exists')
            ->with('images', 'reviews/1/missing.jpg')
            ->once()
            ->andReturn(false);

        $this->storage
            ->shouldNotReceive('delete');

        $result = $this->service->delete($image);

        $this->assertTrue($result);
    }

    // ========================================
    // findByHash() 테스트
    // ========================================

    /**
     * 해시로 이미지를 찾아 돌려주는지 확인
     *
     * @scenario case=find_by_hash
     *
     * @effects review_image_found_by_hash
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_find_by_hash_returns_image(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);

        $found = $this->service->findByHash($image->hash);

        $this->assertNotNull($found);
        $this->assertEquals($image->id, $found->id);
    }

    /**
     * 모르는 해시로 찾으면 null 을 돌려주는지 확인
     *
     * @scenario case=find_unknown_hash
     *
     * @effects review_image_find_returns_null_for_unknown_hash
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_find_by_hash_returns_null_when_not_found(): void
    {
        $found = $this->service->findByHash('nonexistent1');

        $this->assertNull($found);
    }

    // ========================================
    // download() 테스트
    // ========================================

    /**
     * 모르는 해시의 다운로드는 null 을 돌려주는지 확인
     *
     * @scenario case=download_unknown_hash
     *
     * @effects review_image_download_returns_null_for_unknown_hash
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_download_returns_null_when_hash_not_found(): void
    {
        $result = $this->service->download('nonexistent1');

        $this->assertNull($result);
    }

    /**
     * 스토리지가 응답을 주지 못하면 다운로드가 null 을 돌려주는지 확인
     *
     * @scenario case=download_file_missing
     *
     * @effects review_image_download_returns_null_when_file_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_download_returns_null_when_file_missing(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'path' => 'reviews/1/missing.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]);

        // 서빙은 행 disk 기준으로 스토리지를 해석한다 (혼재 운용, 공개#100)
        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        $this->storage
            ->shouldReceive('response')
            ->once()
            ->andReturn(null);

        $result = $this->service->download($image->hash);

        $this->assertNull($result);
    }
}
