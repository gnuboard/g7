<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * CategoryImageService::pruneTempUploads() 단위 테스트 (#876)
 *
 * 파일 삭제에 실패한 행은 지우지 않고 남겨야 다음 회차가 다시 시도한다 — 행을 먼저 지우면 그 파일을
 * 가리키는 기록이 사라져 아무도 되찾지 못하는 고아 파일이 된다.
 */
class CategoryImagePruneTempUploadsTest extends ModuleTestCase
{
    /** @var MockInterface&CategoryImageRepositoryInterface */
    private $repository;

    /** @var MockInterface&StorageInterface */
    private $storage;

    private CategoryImageService $service;

    /**
     * 저장소와 StorageInterface 를 목으로 만든 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(CategoryImageRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->service = new CategoryImageService($this->repository, $this->storage);
    }

    /**
     * Mockery 기대값을 검증하고 정리합니다.
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
     * 정리 대상 임시 이미지 행을 만듭니다 (DB 에 쓰지 않음).
     *
     * @param  int  $id  이미지 ID
     * @param  string  $path  이미지 경로
     * @return CategoryImage 이미지 행
     */
    private function tempImage(int $id, string $path): CategoryImage
    {
        $image = new CategoryImage(['temp_key' => 'tk', 'path' => $path]);
        $image->id = $id;

        return $image;
    }

    /**
     * 파일 삭제에 실패한 행은 남기고 실패로 세며, 나머지 행은 계속 처리한다.
     *
     * @effects category_temp_prune_keeps_record_when_file_delete_fails
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function failed_file_delete_keeps_the_record_and_continues(): void
    {
        $stuck = $this->tempImage(1, 'category/2026/11/10/stuck.png');
        $ok = $this->tempImage(2, 'category/2026/11/10/ok.png');

        $this->repository->shouldReceive('findStaleTempImages')->once()->andReturn(new EloquentCollection([$stuck, $ok]));

        $this->storage->shouldReceive('exists')->with('images', $stuck->path)->andReturn(true);
        $this->storage->shouldReceive('delete')->once()->with('images', $stuck->path)->andReturn(false);
        $this->storage->shouldReceive('exists')->with('images', $ok->path)->andReturn(true);
        $this->storage->shouldReceive('delete')->once()->with('images', $ok->path)->andReturn(true);

        $this->repository->shouldNotReceive('forceDeleteById')->with(1);
        $this->repository->shouldReceive('forceDeleteById')->once()->with(2)->andReturn(true);

        $result = $this->service->pruneTempUploads(2, 500);

        $this->assertSame(['scanned' => 2, 'deleted' => 1, 'failed' => 1], $result);
    }

    /**
     * 파일이 이미 없으면(폼에서 뺀 임시 이미지) 파일 삭제를 부르지 않고 행만 지운다.
     *
     * @effects category_temp_prune_removes_soft_deleted_temp_rows
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function missing_file_only_removes_the_record(): void
    {
        $gone = $this->tempImage(3, 'category/2026/11/10/gone.png');

        $this->repository->shouldReceive('findStaleTempImages')->once()->andReturn(new EloquentCollection([$gone]));
        $this->storage->shouldReceive('exists')->once()->with('images', $gone->path)->andReturn(false);
        $this->storage->shouldNotReceive('delete');
        $this->repository->shouldReceive('forceDeleteById')->once()->with(3)->andReturn(true);

        $this->assertSame(['scanned' => 1, 'deleted' => 1, 'failed' => 0], $this->service->pruneTempUploads(2, 500));
    }
}
