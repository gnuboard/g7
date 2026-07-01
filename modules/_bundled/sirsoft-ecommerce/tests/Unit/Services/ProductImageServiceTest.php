<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductImageUploadLimitException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ProductImageService 단위 테스트
 *
 * StorageInterface 기반 상품 이미지 업로드, 삭제, 순서변경, 임시연결 등을 테스트합니다.
 */
class ProductImageServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductImageService $service;

    /** @var MockInterface&ProductImageRepositoryInterface */
    private $repository;

    /** @var MockInterface&StorageInterface */
    private $storage;

    /** @var MockInterface&ProductRepositoryInterface */
    private $productRepository;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);

        // Hook listener job 차단 (mock 모델 deserialize 시 null TypeError 방지)
        Queue::fake();

        $this->repository = Mockery::mock(ProductImageRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);

        $this->service = new ProductImageService(
            $this->repository,
            $this->storage,
            $this->productRepository
        );

        $this->user = User::factory()->create();
        Auth::login($this->user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function test_upload_stores_image_with_product_id(): void
    {
        // Arrange
        $productId = 1;
        $file = UploadedFile::fake()->image('product.jpg', 800, 600);

        $product = new Product(['product_code' => 'PROD-001']);
        $product->id = $productId;

        $this->productRepository
            ->shouldReceive('find')
            ->once()
            ->with($productId)
            ->andReturn($product);

        // 개수 상한 검증용 현재 개수 조회 (안전망)
        $this->repository
            ->shouldReceive('getByProductId')
            ->with($productId, 'main')
            ->andReturn(new EloquentCollection([]));

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path) {
                return $category === 'images'
                    && str_contains($path, 'products/PROD-001/')
                    && str_ends_with($path, '.jpg');
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        $this->repository
            ->shouldReceive('getMaxSortOrder')
            ->once()
            ->with($productId, 'main')
            ->andReturn(0);

        $expectedImage = new ProductImage([
            'product_id' => $productId,
            'original_filename' => 'product.jpg',
            'disk' => 'local',
            'collection' => 'main',
            'sort_order' => 1,
            'width' => 800,
            'height' => 600,
        ]);
        $expectedImage->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) use ($productId) {
                return $data['product_id'] === $productId
                    && $data['original_filename'] === 'product.jpg'
                    && $data['disk'] === 'local'
                    && $data['collection'] === 'main'
                    && $data['sort_order'] === 1
                    && str_contains($data['path'], 'products/PROD-001/')
                    && $data['created_by'] === $this->user->id;
            }))
            ->andReturn($expectedImage);

        // Act
        $result = $this->service->upload($file, $productId);

        // Assert
        $this->assertEquals(1, $result->id);
        $this->assertEquals('product.jpg', $result->original_filename);
    }

    #[Test]
    public function test_upload_with_temp_key_creates_temp_image(): void
    {
        // Arrange
        $tempKey = 'temp-uuid-789';
        $file = UploadedFile::fake()->image('temp-photo.png');

        // 개수 상한 검증용 현재 개수 조회 (안전망)
        $this->repository
            ->shouldReceive('getByTempKey')
            ->with($tempKey, 'main')
            ->andReturn(new EloquentCollection([]));

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path) use ($tempKey) {
                return $category === 'images'
                    && str_contains($path, "products/temp/{$tempKey}/");
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        $this->repository
            ->shouldReceive('getMaxSortOrderByTempKey')
            ->once()
            ->with($tempKey, 'main')
            ->andReturn(0);

        $expectedImage = new ProductImage([
            'product_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => 'temp-photo.png',
        ]);
        $expectedImage->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) use ($tempKey) {
                return $data['product_id'] === null
                    && $data['temp_key'] === $tempKey
                    && $data['original_filename'] === 'temp-photo.png';
            }))
            ->andReturn($expectedImage);

        // Act
        $result = $this->service->upload($file, null, 'main', $tempKey);

        // Assert
        $this->assertNull($result->product_id);
        $this->assertEquals($tempKey, $result->temp_key);
    }

    #[Test]
    public function test_upload_throws_when_product_image_limit_reached(): void
    {
        // Arrange: 상품에 이미 상한(20)만큼 이미지 존재
        $productId = 1;
        $file = UploadedFile::fake()->image('overflow.jpg');

        $existing = new EloquentCollection(array_map(
            fn ($i) => (new ProductImage(['collection' => 'main']))->forceFill(['id' => $i]),
            range(1, 20),
        ));

        $this->repository
            ->shouldReceive('getByProductId')
            ->with($productId, 'main')
            ->andReturn($existing);

        // Act & Assert: 도메인 예외
        $this->expectException(ProductImageUploadLimitException::class);

        $this->service->upload($file, $productId);
    }

    #[Test]
    public function test_upload_throws_when_temp_image_limit_reached(): void
    {
        // Arrange: 임시키에 이미 상한(20)만큼 이미지 존재
        $tempKey = 'temp-limit-key';
        $file = UploadedFile::fake()->image('overflow.png');

        $existing = new EloquentCollection(array_map(
            fn ($i) => (new ProductImage(['collection' => 'main']))->forceFill(['id' => $i]),
            range(1, 20),
        ));

        $this->repository
            ->shouldReceive('getByTempKey')
            ->with($tempKey, 'main')
            ->andReturn($existing);

        // Act & Assert
        $this->expectException(ProductImageUploadLimitException::class);

        $this->service->upload($file, null, 'main', $tempKey);
    }

    #[Test]
    public function test_upload_fires_hooks(): void
    {
        // Arrange
        $beforeFired = false;
        $afterFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-ecommerce.product-image.before_upload', function () use (&$beforeFired) {
            $beforeFired = true;
        });
        HookManager::addFilter('sirsoft-ecommerce.product-image.filter_upload_file', function ($file) use (&$filterApplied) {
            $filterApplied = true;

            return $file;
        });
        HookManager::addAction('sirsoft-ecommerce.product-image.after_upload', function () use (&$afterFired) {
            $afterFired = true;
        });

        $file = UploadedFile::fake()->image('test.jpg');
        $product = new Product(['product_code' => 'PROD-001']);
        $product->id = 1;

        $this->productRepository->shouldReceive('find')->andReturn($product);
        $this->repository->shouldReceive('getByProductId')->with(1, 'main')->andReturn(new EloquentCollection([]));
        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->repository->shouldReceive('getMaxSortOrder')->andReturn(0);
        $this->repository->shouldReceive('create')->andReturn(new ProductImage(['id' => 1]));

        // Act
        $this->service->upload($file, 1);

        // Assert
        $this->assertTrue($beforeFired);
        $this->assertTrue($filterApplied);
        $this->assertTrue($afterFired);

        // Cleanup
        HookManager::clearAction('sirsoft-ecommerce.product-image.before_upload');
        HookManager::clearFilter('sirsoft-ecommerce.product-image.filter_upload_file');
        HookManager::clearAction('sirsoft-ecommerce.product-image.after_upload');
    }

    #[Test]
    public function test_delete_removes_file_from_storage(): void
    {
        // Arrange
        $image = new ProductImage([
            'product_id' => 1,
            'path' => 'products/PROD-001/image.jpg',
            'collection' => 'main',
        ]);
        $image->id = 1;

        $this->repository->shouldReceive('findById')->once()->with(1)->andReturn($image);
        $this->storage->shouldReceive('exists')->once()->with('images', 'products/PROD-001/image.jpg')->andReturn(true);
        $this->storage->shouldReceive('delete')->once()->with('images', 'products/PROD-001/image.jpg')->andReturn(true);
        $this->repository->shouldReceive('delete')->once()->with(1)->andReturn(true);
        $this->repository->shouldReceive('getByProductId')->once()->with(1, 'main')->andReturn(new EloquentCollection([]));

        // Act
        $result = $this->service->delete(1);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_link_temp_images_moves_files_and_updates_records(): void
    {
        // Arrange
        $tempKey = 'temp-uuid-789';
        $productId = 5;
        $productCode = 'PROD-005';

        $tempImage = new ProductImage([
            'product_id' => null,
            'temp_key' => $tempKey,
            'stored_filename' => 'abc123.jpg',
            'path' => "products/temp/{$tempKey}/abc123.jpg",
        ]);
        $tempImage->id = 10;

        $this->repository
            ->shouldReceive('getByTempKey')
            ->once()
            ->with($tempKey)
            ->andReturn(new EloquentCollection([$tempImage]));

        // linkTempImages 는 복사 이미지 뒤에 배치하기 위해 getMaxSortOrder 조회
        $this->repository
            ->shouldReceive('getMaxSortOrder')
            ->once()
            ->with($productId, 'main')
            ->andReturn(0);

        // get → put → delete (파일 이동)
        $this->storage
            ->shouldReceive('get')
            ->once()
            ->with('images', "products/temp/{$tempKey}/abc123.jpg")
            ->andReturn('file-contents');

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->with('images', "products/{$productCode}/abc123.jpg", 'file-contents')
            ->andReturn(true);

        $this->storage
            ->shouldReceive('delete')
            ->once()
            ->with('images', "products/temp/{$tempKey}/abc123.jpg")
            ->andReturn(true);

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with(10, Mockery::on(function ($data) use ($productId, $productCode) {
                return $data['product_id'] === $productId
                    && $data['temp_key'] === null
                    && $data['path'] === "products/{$productCode}/abc123.jpg";
            }))
            ->andReturn($tempImage);

        $this->storage
            ->shouldReceive('deleteDirectory')
            ->once()
            ->with('images', "products/temp/{$tempKey}");

        // Act
        $result = $this->service->linkTempImages($tempKey, $productId, $productCode);

        // Assert
        $this->assertEquals(1, $result);
    }

    #[Test]
    public function test_reorder_updates_image_order(): void
    {
        // Arrange
        $orders = [1 => 3, 2 => 1, 3 => 2];

        $this->repository
            ->shouldReceive('reorder')
            ->once()
            ->with($orders)
            ->andReturn(true);

        // Act
        $result = $this->service->reorder($orders);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_copy_from_source_copies_file_and_creates_record(): void
    {
        // Arrange
        $sourceHash = 'abc123def456';
        $targetProductId = 10;
        $targetProductCode = 'PROD-010';

        $sourceImage = new ProductImage([
            'product_id' => 1,
            'original_filename' => 'photo.jpg',
            'stored_filename' => 'uuid-original.jpg',
            'disk' => 'local',
            'path' => 'products/PROD-001/uuid-original.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
            'width' => 800,
            'height' => 600,
            'alt_text' => ['ko' => '상품 이미지'],
            'collection' => 'main',
        ]);
        $sourceImage->id = 1;

        $this->repository
            ->shouldReceive('findByHash')
            ->once()
            ->with($sourceHash)
            ->andReturn($sourceImage);

        $this->storage
            ->shouldReceive('get')
            ->once()
            ->with('images', 'products/PROD-001/uuid-original.jpg')
            ->andReturn('file-binary-content');

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $content) use ($targetProductCode) {
                return $category === 'images'
                    && str_contains($path, "products/{$targetProductCode}/")
                    && str_ends_with($path, '.jpg')
                    && $content === 'file-binary-content';
            })
            ->andReturn(true);

        $createdImage = new ProductImage([
            'product_id' => $targetProductId,
            'original_filename' => 'photo.jpg',
        ]);
        $createdImage->id = 99;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) use ($targetProductId, $targetProductCode) {
                return $data['product_id'] === $targetProductId
                    && $data['original_filename'] === 'photo.jpg'
                    && $data['mime_type'] === 'image/jpeg'
                    && $data['file_size'] === 12345
                    && $data['width'] === 800
                    && $data['height'] === 600
                    && $data['is_thumbnail'] === true
                    && $data['sort_order'] === 0
                    && $data['created_by'] === $this->user->id
                    && str_contains($data['path'], "products/{$targetProductCode}/");
            }))
            ->andReturn($createdImage);

        // Act
        $result = $this->service->copyFromSource($sourceHash, $targetProductId, $targetProductCode, true, 0);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals(99, $result->id);
        $this->assertEquals('photo.jpg', $result->original_filename);
    }

    #[Test]
    public function test_copy_from_source_returns_null_when_source_not_found(): void
    {
        // Arrange
        $this->repository
            ->shouldReceive('findByHash')
            ->once()
            ->with('nonexistent123')
            ->andReturn(null);

        // Act
        $result = $this->service->copyFromSource('nonexistent123', 10, 'PROD-010');

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function test_copy_from_source_returns_null_when_file_not_found(): void
    {
        // Arrange
        $sourceImage = new ProductImage([
            'product_id' => 1,
            'stored_filename' => 'missing.jpg',
            'path' => 'products/PROD-001/missing.jpg',
        ]);
        $sourceImage->id = 1;

        $this->repository
            ->shouldReceive('findByHash')
            ->once()
            ->andReturn($sourceImage);

        $this->storage
            ->shouldReceive('get')
            ->once()
            ->with('images', 'products/PROD-001/missing.jpg')
            ->andReturn(null);

        // Act
        $result = $this->service->copyFromSource('hash123', 10, 'PROD-010');

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function test_set_thumbnail_updates_thumbnail_flag(): void
    {
        // Arrange
        $productId = 1;
        $imageId = 5;

        $images = new EloquentCollection([
            tap(new ProductImage(['is_thumbnail' => true]), fn ($img) => $img->id = 3),
            tap(new ProductImage(['is_thumbnail' => false]), fn ($img) => $img->id = 5),
        ]);

        $this->repository
            ->shouldReceive('getByProductId')
            ->once()
            ->with($productId)
            ->andReturn($images);

        // 기존 대표 이미지 해제
        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with(3, ['is_thumbnail' => false])
            ->andReturn($images[0]);

        // 새 대표 이미지 설정
        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with($imageId, ['is_thumbnail' => true])
            ->andReturn($images[1]);

        // Act
        $result = $this->service->setThumbnail($productId, $imageId);

        // Assert
        $this->assertTrue($result);
    }
}
