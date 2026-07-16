<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * CategoryImageService 단위 테스트
 *
 * StorageInterface 기반 이미지 업로드, 삭제 등을 테스트합니다.
 */
class CategoryImageServiceTest extends TestCase
{
    use RefreshDatabase;

    private CategoryImageService $service;

    /** @var \Mockery\MockInterface&CategoryImageRepositoryInterface */
    private $repository;

    /** @var \Mockery\MockInterface&StorageInterface */
    private $storage;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화
        config(['telescope.enabled' => false]);

        // Mock Repository 생성
        $this->repository = Mockery::mock(CategoryImageRepositoryInterface::class);

        // Mock Storage 생성 (직접 Mock)
        $this->storage = Mockery::mock(StorageInterface::class);

        // Service 생성
        $this->service = new CategoryImageService($this->repository, $this->storage);

        // 테스트 사용자 생성
        $this->user = User::factory()->create();
        Auth::login($this->user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**     */
    #[Test]
    public function test_upload_stores_image_and_creates_record(): void
    {
        // Arrange
        $categoryId = 1;
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        // Storage Mock 기대값
        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $contents) {
                return $category === 'images'
                    && str_contains($path, 'category/')
                    && str_ends_with($path, '.jpg');
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        // Repository Mock 기대값
        $this->repository
            ->shouldReceive('getMaxSortOrder')
            ->once()
            ->with($categoryId, 'main')
            ->andReturn(0);

        $expectedImage = new CategoryImage([
            'category_id' => $categoryId,
            'original_filename' => 'photo.jpg',
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
            ->with(Mockery::on(function ($data) use ($categoryId) {
                return $data['category_id'] === $categoryId
                    && $data['original_filename'] === 'photo.jpg'
                    && $data['disk'] === 'local'
                    && $data['collection'] === 'main'
                    && $data['sort_order'] === 1
                    && $data['created_by'] === $this->user->id;
            }))
            ->andReturn($expectedImage);

        // Act
        $result = $this->service->upload($file, $categoryId);

        // Assert
        $this->assertEquals(1, $result->id);
        $this->assertEquals('photo.jpg', $result->original_filename);
        $this->assertEquals(800, $result->width);
        $this->assertEquals(600, $result->height);
    }

    /**     */
    #[Test]
    public function test_upload_with_temp_key_creates_temp_image(): void
    {
        // Arrange
        $tempKey = 'temp-uuid-456';
        $file = UploadedFile::fake()->image('temp-photo.png');

        $this->storage
            ->shouldReceive('put')
            ->once()
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->once()
            ->andReturn('local');

        $this->repository
            ->shouldReceive('getMaxSortOrderByTempKey')
            ->once()
            ->with($tempKey, 'main')
            ->andReturn(0);

        $expectedImage = new CategoryImage([
            'category_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => 'temp-photo.png',
            'disk' => 'local',
            'collection' => 'main',
            'sort_order' => 1,
        ]);
        $expectedImage->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) use ($tempKey) {
                return $data['category_id'] === null
                    && $data['temp_key'] === $tempKey
                    && $data['original_filename'] === 'temp-photo.png';
            }))
            ->andReturn($expectedImage);

        // Act
        $result = $this->service->upload($file, null, 'main', $tempKey);

        // Assert
        $this->assertNull($result->category_id);
        $this->assertEquals($tempKey, $result->temp_key);
    }

    /**     */
    #[Test]
    public function test_upload_with_alt_text(): void
    {
        // Arrange
        $categoryId = 1;
        $file = UploadedFile::fake()->image('photo.jpg');
        $altText = ['ko' => '카테고리 이미지', 'en' => 'Category Image'];

        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->repository->shouldReceive('getMaxSortOrder')->andReturn(0);

        $expectedImage = new CategoryImage([
            'category_id' => $categoryId,
            'alt_text' => $altText,
        ]);
        $expectedImage->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) use ($altText) {
                return $data['alt_text'] === $altText;
            }))
            ->andReturn($expectedImage);

        // Act
        $result = $this->service->upload($file, $categoryId, 'main', null, $altText);

        // Assert
        $this->assertEquals($altText, $result->alt_text);
    }

    /**     */
    #[Test]
    public function test_upload_fires_hooks(): void
    {
        // Arrange
        $beforeUploadFired = false;
        $afterUploadFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-ecommerce.category-image.before_upload', function () use (&$beforeUploadFired) {
            $beforeUploadFired = true;
        });

        HookManager::addFilter('sirsoft-ecommerce.category-image.filter_upload_file', function ($file) use (&$filterApplied) {
            $filterApplied = true;

            return $file;
        });

        HookManager::addAction('sirsoft-ecommerce.category-image.after_upload', function () use (&$afterUploadFired) {
            $afterUploadFired = true;
        });

        $file = UploadedFile::fake()->image('test.jpg');

        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');
        $this->repository->shouldReceive('getMaxSortOrder')->andReturn(0);

        $image = new CategoryImage(['id' => 1]);
        $this->repository->shouldReceive('create')->andReturn($image);

        // Act
        $this->service->upload($file, 1);

        // Assert
        $this->assertTrue($beforeUploadFired, 'before_upload hook should be fired');
        $this->assertTrue($filterApplied, 'filter_upload_file hook should be applied');
        $this->assertTrue($afterUploadFired, 'after_upload hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.category-image.before_upload');
        HookManager::clearFilter('sirsoft-ecommerce.category-image.filter_upload_file');
        HookManager::clearAction('sirsoft-ecommerce.category-image.after_upload');
    }

    /**     */
    #[Test]
    public function test_delete_removes_file_from_storage(): void
    {
        // Arrange
        $imageId = 1;

        $image = new CategoryImage([
            'category_id' => 1,
            'path' => 'category/2024/01/19/image.jpg',
            'collection' => 'main',
        ]);
        $image->id = $imageId;

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with($imageId)
            ->andReturn($image);

        $this->storage
            ->shouldReceive('exists')
            ->once()
            ->with('images', 'category/2024/01/19/image.jpg')
            ->andReturn(true);

        $this->storage
            ->shouldReceive('delete')
            ->once()
            ->with('images', 'category/2024/01/19/image.jpg')
            ->andReturn(true);

        $this->repository
            ->shouldReceive('delete')
            ->once()
            ->with($imageId)
            ->andReturn(true);

        $this->repository
            ->shouldReceive('getByCategoryId')
            ->once()
            ->with(1, 'main')
            ->andReturn(new EloquentCollection([]));

        // Act
        $result = $this->service->delete($imageId);

        // Assert
        $this->assertTrue($result);
    }

    /**     */
    #[Test]
    public function test_delete_fires_hooks(): void
    {
        // Arrange
        $beforeDeleteFired = false;
        $afterDeleteFired = false;

        HookManager::addAction('sirsoft-ecommerce.category-image.before_delete', function () use (&$beforeDeleteFired) {
            $beforeDeleteFired = true;
        });

        HookManager::addAction('sirsoft-ecommerce.category-image.after_delete', function () use (&$afterDeleteFired) {
            $afterDeleteFired = true;
        });

        $image = new CategoryImage(['category_id' => 1, 'path' => 'test.jpg', 'collection' => 'main']);
        $image->id = 1;

        $this->repository->shouldReceive('findById')->andReturn($image);
        $this->storage->shouldReceive('exists')->andReturn(true);
        $this->storage->shouldReceive('delete')->andReturn(true);
        $this->repository->shouldReceive('delete')->andReturn(true);
        $this->repository->shouldReceive('getByCategoryId')->andReturn(new EloquentCollection([]));

        // Act
        $this->service->delete(1);

        // Assert
        $this->assertTrue($beforeDeleteFired, 'before_delete hook should be fired');
        $this->assertTrue($afterDeleteFired, 'after_delete hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.category-image.before_delete');
        HookManager::clearAction('sirsoft-ecommerce.category-image.after_delete');
    }

    /**     */
    #[Test]
    public function test_linkTempImages_links_temp_files_to_category(): void
    {
        // Arrange
        $tempKey = 'temp-uuid-456';
        $categoryId = 5;

        $this->repository
            ->shouldReceive('linkTempImages')
            ->once()
            ->with($tempKey, $categoryId)
            ->andReturn(3);

        // Act
        $result = $this->service->linkTempImages($tempKey, $categoryId);

        // Assert
        $this->assertEquals(3, $result);
    }

    /**     */
    #[Test]
    public function test_reorder_updates_image_order(): void
    {
        // Arrange
        $orders = [
            1 => 3,
            2 => 1,
            3 => 2,
        ];

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

    /**     */
    #[Test]
    public function test_reorder_fires_hooks(): void
    {
        // Arrange
        $beforeReorderFired = false;
        $afterReorderFired = false;

        HookManager::addAction('sirsoft-ecommerce.category-image.before_reorder', function () use (&$beforeReorderFired) {
            $beforeReorderFired = true;
        });

        HookManager::addAction('sirsoft-ecommerce.category-image.after_reorder', function () use (&$afterReorderFired) {
            $afterReorderFired = true;
        });

        $this->repository->shouldReceive('reorder')->andReturn(true);

        // Act
        $this->service->reorder([1 => 1, 2 => 2]);

        // Assert
        $this->assertTrue($beforeReorderFired, 'before_reorder hook should be fired');
        $this->assertTrue($afterReorderFired, 'after_reorder hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.category-image.before_reorder');
        HookManager::clearAction('sirsoft-ecommerce.category-image.after_reorder');
    }

    /**
     * download 메서드가 StorageInterface.response()를 사용하여 StreamedResponse를 반환하는지 테스트
     */
    #[Test]
    public function test_download_returns_streamed_response(): void
    {
        // Arrange
        $hash = 'abc123def456';
        $image = new CategoryImage([
            'path' => 'category/2024/01/19/image.jpg',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $image->id = 1;

        $this->repository
            ->shouldReceive('findByHash')
            ->once()
            ->with($hash)
            ->andReturn($image);

        $mockResponse = new StreamedResponse(function () {
            echo 'image-data';
        });

        $this->storage
            ->shouldReceive('response')
            ->once()
            ->with('images', 'category/2024/01/19/image.jpg', 'photo.jpg', [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=31536000',
            ])
            ->andReturn($mockResponse);

        // Act
        $result = $this->service->download($hash);

        // Assert
        $this->assertInstanceOf(StreamedResponse::class, $result);
    }

    /**
     * download 메서드가 존재하지 않는 해시에 대해 null을 반환하는지 테스트
     */
    #[Test]
    public function test_download_returns_null_for_unknown_hash(): void
    {
        // Arrange
        $this->repository
            ->shouldReceive('findByHash')
            ->once()
            ->with('unknown12hash')
            ->andReturn(null);

        // Act
        $result = $this->service->download('unknown12hash');

        // Assert
        $this->assertNull($result);
    }

    /**
     * download 메서드가 스토리지에 파일이 없을 때 null을 반환하는지 테스트
     */
    #[Test]
    public function test_download_returns_null_when_file_missing_from_storage(): void
    {
        // Arrange
        $hash = 'abc123def456';
        $image = new CategoryImage([
            'path' => 'category/2024/01/19/missing.jpg',
            'original_filename' => 'missing.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $image->id = 1;

        $this->repository
            ->shouldReceive('findByHash')
            ->once()
            ->with($hash)
            ->andReturn($image);

        $this->storage
            ->shouldReceive('response')
            ->once()
            ->andReturn(null);

        $this->storage
            ->shouldReceive('getDisk')
            ->once()
            ->andReturn('modules');

        // Act
        $result = $this->service->download($hash);

        // Assert
        $this->assertNull($result);
    }

    /**     */
    #[Test]
    public function test_update_modifies_image_metadata(): void
    {
        // Arrange
        $imageId = 1;
        $altText = ['ko' => '수정된 텍스트', 'en' => 'Updated Text'];

        $updatedImage = new CategoryImage([
            'alt_text' => $altText,
        ]);
        $updatedImage->id = $imageId;

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with($imageId, Mockery::on(function ($data) use ($altText) {
                return $data['alt_text'] === $altText;
            }))
            ->andReturn($updatedImage);

        // Act
        $result = $this->service->update($imageId, ['alt_text' => $altText]);

        // Assert
        $this->assertEquals($altText, $result->alt_text);
    }

    /**     */
    #[Test]
    public function test_update_fires_hooks(): void
    {
        // Arrange
        $beforeUpdateFired = false;
        $afterUpdateFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-ecommerce.category-image.before_update', function () use (&$beforeUpdateFired) {
            $beforeUpdateFired = true;
        });

        HookManager::addFilter('sirsoft-ecommerce.category-image.filter_update_data', function ($data) use (&$filterApplied) {
            $filterApplied = true;

            return $data;
        });

        HookManager::addAction('sirsoft-ecommerce.category-image.after_update', function () use (&$afterUpdateFired) {
            $afterUpdateFired = true;
        });

        $image = new CategoryImage(['id' => 1]);
        $this->repository->shouldReceive('update')->andReturn($image);

        // Act
        $this->service->update(1, ['alt_text' => []]);

        // Assert
        $this->assertTrue($beforeUpdateFired, 'before_update hook should be fired');
        $this->assertTrue($filterApplied, 'filter_update_data hook should be applied');
        $this->assertTrue($afterUpdateFired, 'after_update hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.category-image.before_update');
        HookManager::clearFilter('sirsoft-ecommerce.category-image.filter_update_data');
        HookManager::clearAction('sirsoft-ecommerce.category-image.after_update');
    }
}
