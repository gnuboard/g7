<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * ImageUploadService 단위 테스트
 *
 * 업로드 훅 트라이어드(before_upload → filter_upload_file → after_upload) 발화를 검증합니다.
 * upload 자체 동작(저장/기록)은 Feature ImageUploadControllerTest 가 커버합니다.
 */
class ImageUploadServiceTest extends PluginTestCase
{
    private ImageUploadService $service;

    /** @var MockInterface&ImageUploadRepositoryInterface */
    private $repository;

    /** @var MockInterface&StorageInterface */
    private $storage;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(ImageUploadRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);

        $this->service = new ImageUploadService($this->repository, $this->storage);
    }

    /**
     * 테스트가 바꾼 상태를 정리합니다.
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

    /**
     * 이미지 업로드가 before_upload 액션, filter_upload_file 필터, after_upload 액션을 모두 발화하는지 확인합니다.
     *
     * @scenario surface=ckeditor5_image, subscriber=registered
     *
     * @effects before_upload_action_fired, filter_upload_file_applied, after_upload_action_fired
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

        HookManager::addAction('sirsoft-ckeditor5.image.before_upload', function () use (&$beforeUploadFired) {
            $beforeUploadFired = true;
        });

        HookManager::addFilter('sirsoft-ckeditor5.image.filter_upload_file', function ($file) use (&$filterApplied) {
            $filterApplied = true;

            return $file;
        });

        HookManager::addAction('sirsoft-ckeditor5.image.after_upload', function () use (&$afterUploadFired) {
            $afterUploadFired = true;
        });

        $file = UploadedFile::fake()->image('test.jpg');

        $this->storage->shouldReceive('put')->andReturn(true);
        $this->storage->shouldReceive('getDisk')->andReturn('local');

        $record = new Ckeditor5ImageUpload;
        $this->repository->shouldReceive('create')->andReturn($record);

        // Act
        $this->service->upload($file, null);

        // Assert
        $this->assertTrue($beforeUploadFired, 'before_upload hook should be fired');
        $this->assertTrue($filterApplied, 'filter_upload_file hook should be applied');
        $this->assertTrue($afterUploadFired, 'after_upload hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ckeditor5.image.before_upload');
        HookManager::clearFilter('sirsoft-ckeditor5.image.filter_upload_file');
        HookManager::clearAction('sirsoft-ckeditor5.image.after_upload');
    }
}
