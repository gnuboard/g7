<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
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

    /** @var \Mockery\MockInterface&StorageInterface */
    private $storage;

    /** @var \Mockery\MockInterface&EcommerceSettingsService */
    private $settingsService;

    private ProductReview $review;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = Mockery::mock(StorageInterface::class);
        $this->settingsService = Mockery::mock(EcommerceSettingsService::class);

        $this->service = new ProductReviewImageService(
            $this->storage,
            $this->settingsService
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // upload() 테스트
    // ========================================

    #[Test]
    public function test_upload_creates_image_record(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review.max_images', Mockery::any())
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

    #[Test]
    public function test_upload_throws_when_max_images_exceeded(): void
    {
        // 이미 5개의 이미지가 있는 상태
        ProductReviewImage::factory()->count(5)->create([
            'review_id' => $this->review->id,
        ]);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review.max_images', Mockery::any())
            ->andReturn(5);

        $file = UploadedFile::fake()->image('extra.jpg');

        $this->expectException(\RuntimeException::class);

        $this->service->upload($file, $this->review);
    }

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

    // ========================================
    // delete() 테스트
    // ========================================

    #[Test]
    public function test_delete_removes_file_and_record(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'path' => 'reviews/1/test.jpg',
        ]);

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

    #[Test]
    public function test_delete_skips_file_removal_when_not_exists(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'path' => 'reviews/1/missing.jpg',
        ]);

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

    #[Test]
    public function test_find_by_hash_returns_null_when_not_found(): void
    {
        $found = $this->service->findByHash('nonexistent1');

        $this->assertNull($found);
    }

    // ========================================
    // download() 테스트
    // ========================================

    #[Test]
    public function test_download_returns_null_when_hash_not_found(): void
    {
        $result = $this->service->download('nonexistent1');

        $this->assertNull($result);
    }

    #[Test]
    public function test_download_returns_null_when_file_missing(): void
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
            'path' => 'reviews/1/missing.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]);

        $this->storage
            ->shouldReceive('response')
            ->once()
            ->andReturn(null);

        $result = $this->service->download($image->hash);

        $this->assertNull($result);
    }
}
