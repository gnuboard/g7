<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageUploadException;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Services\PendingImageFiles;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/** [case:backend-popup-image-compensation] 행 없는 파일 재정리·성공 행 보호 회귀. */
class PendingImageFilesTest extends PluginTestCase
{
    /**
     * 가짜 디스크와 로컬 대장을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImageDisks();
    }

    /**
     * 대장이 남아도 성공한 이미지 행이 있으면 연결 파일을 지우지 않습니다.
     *
     * @scenario surface=pending_row_protected
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_pending_record_with_image_row_is_never_deleted(): void
    {
        $image = Popup::factory()->withImages()->create()->images()->first();
        $image->update(['path' => '2026/12/'.$image->hash.'.jpg']);
        $driver = $this->imageStorageDriver();
        $driver->put('images', $image->path, 'existing');
        $journal = app(PendingImageFiles::class);
        $journal->remember($image->hash, $image->path, $driver->getDisk());
        $this->travel(3)->days();
        $result = app(PopupImageService::class)->pruneTempUploads(2, 1, false);
        $this->assertSame(['scanned' => 1, 'deleted' => 0, 'failed' => 0], $result);
        $this->assertTrue($driver->exists('images', $image->path));
        $this->assertNotNull($image->fresh());
        $this->assertSame([], $journal->stale(now()->getTimestamp(), 10));
        $this->travelBack();
    }

    /**
     * 대장 기록 실패면 저장소에 쓰기를 시작하지 않습니다.
     *
     * @scenario surface=pending_journal_required
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_journal_failure_prevents_storage_write(): void
    {
        $journal = \Mockery::mock(PendingImageFiles::class);
        $journal->shouldReceive('remember')->once()->andThrow(new PopupImageUploadException);
        $this->app->instance(PendingImageFiles::class, $journal);
        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('getDisk')->andReturn('local');
        $storage->shouldNotReceive('put');
        $service = new PopupImageService(app(PopupImageRepositoryInterface::class), $storage);
        $this->app->instance(PopupImageService::class, $service);
        $this->uploadImage($this->fullAdmin(), 'pc', 'journal-failure')->assertStatus(500)
            ->assertJsonPath('message', __('sirsoft-popup::messages.image.upload_failed'));
    }

    /**
     * 저장소 예외가 나도 대장을 남기며 복구 뒤 같은 경로만 재정리합니다.
     *
     * @scenario surface=pending_retry_exception
     *
     * @effects files_created_or_removed
     *
     * @return void 반환값 없음
     */
    public function test_retry_exception_keeps_record_until_storage_recovers(): void
    {
        $driver = $this->imageStorageDriver();
        $hash = str_repeat('a', 64);
        $path = '2026/12/'.$hash.'.jpg';
        $driver->put('images', $path, 'orphan');
        app(PendingImageFiles::class)->remember($hash, $path, $driver->getDisk());
        $this->travel(3)->days();
        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('withDisk')->with($driver->getDisk())->andReturnSelf();
        $storage->shouldReceive('exists')->andThrow(new \RuntimeException('temporary failure'));
        $service = new PopupImageService(app(PopupImageRepositoryInterface::class), $storage);
        $this->assertSame(['scanned' => 1, 'deleted' => 0, 'failed' => 1], $service->pruneTempUploads(2, 1, false));
        $this->assertTrue($driver->exists('images', $path));
        $this->assertSame(['scanned' => 1, 'deleted' => 1, 'failed' => 0], app(PopupImageService::class)->pruneTempUploads(2, 1, false));
        $this->assertFalse($driver->exists('images', $path));
        $this->travelBack();
    }
}
