<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CategoryImageService 단위 테스트
 *
 * StorageInterface 기반 이미지 업로드, 삭제 등을 테스트합니다.
 */
class CategoryImageServiceTest extends ModuleTestCase
{
    private CategoryImageService $service;

    /** @var MockInterface&CategoryImageRepositoryInterface */
    private $repository;

    /** @var MockInterface&StorageInterface */
    private $storage;

    private User $user;

    /**
     * 저장소와 StorageInterface 를 목으로 만든 카테고리 이미지 서비스를 준비하고 테스트 사용자로 로그인한다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * Mockery 기대값을 검증하고 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 카테고리 이미지를 올리면 images 카테고리의 category/ 경로에 저장되고 작성자와 다음 정렬 순서로 행이 만들어지는지 확인한다.
     *
     * @scenario operation=upload
     *
     * @effects upload_stores_file_and_creates_record
     *
     * @return void 반환값 없음
     */
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

    /**
     * 임시 키로 올리면 카테고리 없이 임시 키 행이 만들어지는지 확인한다.
     *
     * @scenario operation=upload
     *
     * @effects upload_with_temp_key_creates_unlinked_image
     *
     * @return void 반환값 없음
     */
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

    /**
     * 업로드 때 넘긴 다국어 대체 텍스트가 생성 행 데이터에 그대로 실리는지 확인한다.
     *
     * @scenario operation=upload
     *
     * @effects upload_persists_alt_text
     *
     * @return void 반환값 없음
     */
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

    /**
     * 업로드가 before_upload, filter_upload_file, after_upload 훅을 모두 발행하는지 확인한다.
     *
     * @scenario operation=upload
     *
     * @effects upload_fires_before_filter_after_hooks
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

    /**
     * 이미지를 지우면 저장소 파일과 이미지 행이 함께 삭제되는지 확인한다.
     *
     * @scenario operation=delete
     *
     * @effects delete_removes_storage_file_and_record
     *
     * @return void 반환값 없음
     */
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

    /**
     * 삭제가 before_delete, after_delete 훅을 발행하는지 확인한다.
     *
     * @scenario operation=delete
     *
     * @effects delete_fires_before_after_hooks
     *
     * @return void 반환값 없음
     */
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

    /**
     * 임시 키 이미지를 카테고리에 연결하면 저장소가 돌려준 연결 수를 그대로 반환하는지 확인한다.
     *
     * @scenario operation=link_temp
     *
     * @effects link_temp_images_links_to_category
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_link_temp_images_links_temp_files_to_category(): void
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

    /**
     * 이미지 ID 와 순서 맵을 저장소 reorder 에 그대로 넘기는지 확인한다.
     *
     * @scenario operation=reorder
     *
     * @effects reorder_delegates_order_map_to_repository
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정렬 변경이 before_reorder, after_reorder 훅을 발행하는지 확인한다.
     *
     * @scenario operation=reorder
     *
     * @effects reorder_fires_before_after_hooks
     *
     * @return void 반환값 없음
     */
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
     *
     * 헤더 계약은 11차 G3 라운드 B11-12 로 **강화**되었고, 14차에 그 계약의 소유자가 바뀌었다 —
     * `Cache-Control`·`ETag`·`Expires`·304 판정은 코어 단일 지점(`streamedFileResponse()`)이
     * 만들고 서비스는 스트림 + 검증자 메타(`getFileInfo()`)만 돌려준다. 서비스가 그 헤더를
     * 스토리지에 넘기면 코어 지점이 덮어쓰거나 갈린 사본이 되므로, 여기서는 **넘기지 않는
     * 것**을 단언한다. HTTP 계약(검증자·304·환경별 정책)은 `CategoryImageServiceRowDiskTest`
     * 가 실제 라우트로 잰다.
     *
     * @scenario operation=download
     *
     * @effects download_streams_without_cache_policy_headers
     *
     * @return void 반환값 없음
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
            'file_size' => 1234,
        ]);
        $image->id = 1;
        // 검증자 소스가 되는 행 메타 — 미저장 모델은 updated_at 이 null 이라
        // Last-Modified 축을 재지 못한다.
        $image->updated_at = Carbon::parse('2026-08-01 09:30:00');

        $this->repository
            ->shouldReceive('findByHash')
            ->twice()
            ->with($hash)
            ->andReturn($image);

        $mockResponse = new StreamedResponse(function () {
            echo 'image-data';
        });

        $capturedHeaders = null;

        $this->storage
            ->shouldReceive('response')
            ->twice()
            ->withArgs(function ($category, $path, $filename, $headers) use (&$capturedHeaders) {
                $capturedHeaders = $headers;

                return $category === 'images'
                    && $path === 'category/2024/01/19/image.jpg'
                    && $filename === 'photo.jpg';
            })
            ->andReturn($mockResponse);

        // Act
        $result = $this->service->download($hash);

        // Assert
        $this->assertInstanceOf(StreamedResponse::class, $result);

        $this->assertSame('image/jpeg', $capturedHeaders['Content-Type']);

        $this->assertSame(
            gmdate('D, d M Y H:i:s', $image->updated_at->getTimestamp()).' GMT',
            $capturedHeaders['Last-Modified'] ?? null,
            'Last-Modified 는 행 메타(updated_at)에서 결정적으로 만들어져야 한다',
        );

        // 캐시 정책·ETag 는 코어 지점의 몫이다 — 서비스가 넘기면 사본이 된다.
        $this->assertArrayNotHasKey('Cache-Control', $capturedHeaders, '캐시 정책은 코어 streamedFileResponse() 가 환경에 따라 정한다');
        $this->assertArrayNotHasKey('ETag', $capturedHeaders, 'ETag 는 코어 지점이 etag_source 로 만든다');

        // 서빙 정보는 코어 형제(`AttachmentService::getFileInfo()`)와 같은 형태다.
        $info = $this->service->getFileInfo($hash);

        $this->assertSame($mockResponse, $info['response']);
        $this->assertSame(
            // 미저장 모델은 `disk` 가 null 이라 첫 세그먼트가 빈 문자열이다.
            '|category/2024/01/19/image.jpg|'.$image->updated_at->getTimestamp().'|1234',
            $info['etag_source'],
        );
        $this->assertSame($image->updated_at->getTimestamp(), $info['last_modified']);
        $this->assertSame('image/jpeg', $info['mime_type']);
        $this->assertSame(CategoryImageService::IMAGE_CACHE_MAX_AGE, $info['max_age']);
    }

    /**
     * download 메서드가 존재하지 않는 해시에 대해 null을 반환하는지 테스트
     *
     * @scenario operation=download
     *
     * @effects download_returns_null_for_unknown_hash
     *
     * @return void 반환값 없음
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
     *
     * @scenario operation=download
     *
     * @effects download_returns_null_when_file_missing
     *
     * @return void 반환값 없음
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

    /**
     * 대체 텍스트 수정 데이터가 저장소 update 로 전달되고 수정된 행이 돌아오는지 확인한다.
     *
     * @scenario operation=update
     *
     * @effects update_modifies_alt_text
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수정이 before_update, filter_update_data, after_update 훅을 모두 발행하는지 확인한다.
     *
     * @scenario operation=update
     *
     * @effects update_fires_before_filter_after_hooks
     *
     * @return void 반환값 없음
     */
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
