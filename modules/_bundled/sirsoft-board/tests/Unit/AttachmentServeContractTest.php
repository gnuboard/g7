<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 첨부 서빙 계약 테스트 (코어 형제와의 강도 패리티)
 *
 * 코어 형제(`App\Services\AttachmentService::getFileInfo()` ·
 * `Api\Public\PublicAttachmentController::download()` ·
 * `Api\Public\PublicTemplateController::serveFile()`)가 이미 갖춘 서빙 계약을
 * 이 모듈의 같은 통로도 갖는지 고정합니다.
 *
 * - 조건부 요청의 `Last-Modified`/`If-Modified-Since` 축 (행 메타에서 결정적)
 * - `Content-Length` 를 행 메타로 선지정 (원격 디스크의 추가 메타 조회 생략)
 * - 형제 public 메서드(`preview()`)가 같은 서빙 지점을 경유하는가
 */
class AttachmentServeContractTest extends ModuleTestCase
{
    private AttachmentService $service;

    /** @var MockInterface&AttachmentRepositoryInterface */
    private $repository;

    /** 픽스처 행이 쓰는 디스크 — mock 의 `getDisk()` 와 같아야 같은 인스턴스가 쓰인다. */
    private const FIXTURE_DISK = 'public';

    /** @var MockInterface&StorageInterface */
    private $storage;

    /**
     * 첨부와 게시판 저장소, 스토리지를 모의 객체로 두고 시험 대상 AttachmentService 를 조립한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['telescope.enabled' => false]);

        $this->repository = Mockery::mock(AttachmentRepositoryInterface::class);
        $boardRepository = Mockery::mock(BoardRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        // 서빙 경로가 **행의 disk** 를 따르게 되면서(10차 G3 라운드 B10-3) 스토리지
        // 해석이 `storageForRow()` 를 지난다. 그 판정의 첫 축이 「카테고리 기본 디스크와
        // 같은가」이므로 mock 이 그 질문에 답해야 한다. 픽스처 행의 `disk` 와 같은 값을
        // 돌려주면 같은 인스턴스가 그대로 쓰인다 — 이 스위트가 재는 것은 디스크 해석이
        // 아니라 서빙 지점의 단일성이므로, 그 축을 여기서 흔들지 않는다.
        $this->storage->shouldReceive('getDisk')->andReturn(self::FIXTURE_DISK);

        $mockBoard = Mockery::mock(Board::class)->makePartial();
        $mockBoard->id = 1;
        $boardRepository->shouldReceive('findBySlug')->andReturn($mockBoard);

        $this->service = new AttachmentService($this->repository, $boardRepository, $this->storage);
    }

    /**
     * Mockery 기대값을 검증하고 닫는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 테스트용 첨부 모델 (DB 미저장 — 서빙 계약만 본다)
     *
     * @return Attachment 첨부 모델
     */
    private function makeAttachment(): Attachment
    {
        $attachment = new Attachment;
        $attachment->forceFill([
            'id' => 77,
            'board_id' => 1,
            'post_id' => null,
            'hash' => 'servecontrac',
            'original_filename' => '사진.jpg',
            'stored_filename' => 'uuid-value.jpg',
            'disk' => self::FIXTURE_DISK,
            'path' => 'preview-cache/2026/08/28/uuid-value.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 4096,
            'collection' => 'attachments',
            'order' => 1,
            'updated_at' => Carbon::parse('2026-08-20 11:22:33'),
        ]);

        return $attachment;
    }

    /**
     * getFileInfo() 는 조건부 요청용 수정 시각을 함께 돌려준다.
     *
     * 값이 없으면 컨트롤러가 `Last-Modified` 를 내보낼 수단이 없고, ETag 를 쓰지 않고
     * `If-Modified-Since` 로만 재검증하는 프록시가 매 요청 전문을 받아 간다.
     * 코어 형제(`App\Services\AttachmentService::getFileInfo()`)는 `last_modified` 를 담는다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects get_file_info_carries_last_modified_from_row_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_file_info_carries_last_modified_from_row_meta(): void
    {
        $attachment = $this->makeAttachment();
        $this->repository->shouldReceive('findById')->with('preview-cache', 77)->andReturn($attachment);
        $this->storage->shouldReceive('exists')->andReturn(true);

        $fileInfo = $this->service->getFileInfo('preview-cache', 77);

        $this->assertIsArray($fileInfo);
        $this->assertArrayHasKey('last_modified', $fileInfo, 'getFileInfo() 는 last_modified 를 담아야 합니다');
        $this->assertSame(
            Carbon::parse('2026-08-20 11:22:33')->getTimestamp(),
            $fileInfo['last_modified'],
            'last_modified 는 ETag 소스와 같은 행 메타(updated_at)에서 와야 합니다'
        );
    }

    /**
     * getFileInfo() 는 Content-Length 선지정용 크기를 함께 돌려준다.
     *
     * @scenario serving_type=download, contract_axis=content_length
     *
     * @effects get_file_info_carries_size_from_row_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_file_info_carries_size_from_row_meta(): void
    {
        $attachment = $this->makeAttachment();
        $this->repository->shouldReceive('findById')->with('preview-cache', 77)->andReturn($attachment);
        $this->storage->shouldReceive('exists')->andReturn(true);

        $fileInfo = $this->service->getFileInfo('preview-cache', 77);

        $this->assertIsArray($fileInfo);
        $this->assertArrayHasKey('size', $fileInfo, 'getFileInfo() 는 size 를 담아야 합니다');
        $this->assertSame(4096, $fileInfo['size']);
    }

    /**
     * getFileInfo() 는 etag_source 를 항상 채운다 (컨트롤러의 조용한 폴백이 불필요한 근거).
     *
     * 컨트롤러가 `$fileInfo['etag_source'] ?? $fileInfo['path']` 로 폴백하면 그 자리가
     * 실행될 때 ETag 가 내용과 무관한 불변값이 되어 재검증이 영원히 304 가 된다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects get_file_info_always_fills_etag_source
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_file_info_always_fills_etag_source(): void
    {
        $attachment = $this->makeAttachment();
        $this->repository->shouldReceive('findById')->with('preview-cache', 77)->andReturn($attachment);
        $this->storage->shouldReceive('exists')->andReturn(true);

        $fileInfo = $this->service->getFileInfo('preview-cache', 77);

        $this->assertIsArray($fileInfo);
        $this->assertArrayHasKey('etag_source', $fileInfo);
        $this->assertNotSame($fileInfo['path'], $fileInfo['etag_source']);
    }

    /**
     * 컨트롤러는 ETag 소스를 조용히 폴백하지 않는다.
     *
     * 폴백은 도달하지 않는 분기라 런타임으로는 관측되지 않는다 — 소스 계약으로 고정한다.
     * 코어 형제 둘(PublicAttachmentController · PublicTemplateController)에는 이 폴백이 없다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects controller_does_not_fall_back_to_path_for_etag_source
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_controller_does_not_fall_back_to_path_for_etag_source(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Http/Controllers/User/AttachmentController.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            "\$fileInfo['etag_source'] ?? ",
            $source,
            'ETag 소스는 폴백 없이 getFileInfo() 값을 그대로 써야 합니다 (path 폴백은 영구 304 를 만든다)'
        );
    }

    /**
     * streamFile() 은 Content-Length 를 행 메타로 선지정한다.
     *
     * 넘기지 않으면 Flysystem 어댑터가 size() 로 원격 메타를 한 번 더 조회한다.
     * 코어 형제(`App\Services\AttachmentService::getFileInfo()`)는 선지정한다.
     *
     * @scenario serving_type=download, contract_axis=content_length
     *
     * @effects stream_file_declares_content_length_from_row_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_stream_file_declares_content_length_from_row_meta(): void
    {
        $captured = null;

        $this->storage->shouldReceive('response')
            ->once()
            ->andReturnUsing(function ($category, $path, $filename, $headers) use (&$captured) {
                $captured = $headers;

                return new StreamedResponse(fn () => null);
            });

        $this->service->streamFile([
            'path' => 'preview-cache/2026/08/28/uuid-value.jpg',
            'mime_type' => 'image/jpeg',
            'filename' => '사진.jpg',
            'size' => 4096,
        ]);

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('Content-Length', $captured, 'streamFile() 은 Content-Length 를 선지정해야 합니다');
        $this->assertSame('4096', $captured['Content-Length']);
    }

    /**
     * 형제 public 메서드 preview() 도 같은 서빙 지점을 경유한다.
     *
     * 종전에는 이 메서드만 존재 검사 없이 응답을 만들고 원문 헤더
     * `Content-Disposition: inline` 으로 스토리지의 파일 이름 선언을 덮었다.
     * 서비스는 확장에 열려 있으므로 "지금 호출부가 없다" 는 방어가 아니다.
     *
     * @scenario serving_type=preview, contract_axis=filename_declaration
     *
     * @effects preview_serves_through_the_same_stream_point
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_preview_serves_through_the_same_stream_point(): void
    {
        $attachment = $this->makeAttachment();
        $this->repository->shouldReceive('findById')->with('preview-cache', 77)->andReturn($attachment);

        // 존재 검사를 경유해야 한다 (getFileInfo 의 계약)
        $this->storage->shouldReceive('exists')->once()->andReturn(true);

        $captured = null;
        $this->storage->shouldReceive('response')
            ->once()
            ->andReturnUsing(function ($category, $path, $filename, $headers) use (&$captured) {
                $captured = $headers;

                return new StreamedResponse(fn () => null);
            });

        $this->service->preview('preview-cache', 77);

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey(
            'Content-Disposition',
            $captured,
            '원문 Content-Disposition 은 스토리지의 파일 이름 선언을 덮는다 — 넘기지 않아야 합니다'
        );
        $this->assertArrayHasKey('Content-Length', $captured);
    }

    /**
     * 서빙은 **행의 disk** 를 따른다 (존재 검사 · 스트림 · 다운로드 셋 다).
     *
     * 같은 클래스의 삭제·정리 경로는 이미 `storageForRow()` 를 쓰고 코어 형제도
     * `withDisk($attachment->disk)` 를 쓰는데 서빙 경로만 카테고리 기본 디스크로 갔다.
     * 그러면서 `etag_source` 는 `disk` 를 싣는다 — 검증자는 디스크가 표현을 가른다고
     * 선언하고 서빙은 그것을 부정하는 자기모순이었다. 증상은 404 + 로그 한 줄이라
     * 디스크를 옮긴 사이트에서 썸네일이 조용히 전멸한다 (10차 G3 라운드 B10-3).
     *
     * @scenario serving_type=preview, serving_type=download, contract_axis=row_disk
     *
     * @effects serving_follows_the_disk_recorded_on_the_row
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_serving_follows_the_disk_recorded_on_the_row(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->disk = 'remote-archive';

        // 그 이름의 디스크가 실재해야 `withDisk()` 로 내려간다 (미등록이면 폴백이 정상).
        config(['filesystems.disks.remote-archive' => ['driver' => 'local', 'root' => sys_get_temp_dir()]]);

        $this->repository->shouldReceive('findById')->with('preview-cache', 77)->andReturn($attachment);

        $rowStorage = Mockery::mock(StorageInterface::class);
        $rowStorage->shouldReceive('exists')->once()->andReturn(true);
        $rowStorage->shouldReceive('response')->once()->andReturn(new StreamedResponse(fn () => null));

        $this->storage->shouldReceive('withDisk')
            ->with('remote-archive')
            ->atLeast()->once()
            ->andReturn($rowStorage);

        // 기본 디스크 인스턴스는 서빙에 쓰이면 안 된다.
        $this->storage->shouldNotReceive('exists');
        $this->storage->shouldNotReceive('response');

        $fileInfo = $this->service->getFileInfo('preview-cache', 77);

        $this->assertIsArray($fileInfo);
        $this->assertSame(
            'remote-archive',
            $fileInfo['disk'],
            'getFileInfo() 는 서빙이 따라야 할 디스크를 함께 실어 보내야 합니다 — streamFile() 은 행을 보지 못한다',
        );

        $this->assertNotNull($this->service->streamFile($fileInfo));
    }
}
