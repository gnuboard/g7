<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Services;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 페이지 첨부 서빙 계약 테스트 (형제 sirsoft-board · 코어와의 강도 패리티)
 *
 * 세 축을 고정합니다 (11차 G3 라운드 B11-1 · B11-2 · B11-3).
 *
 * 1) 다운로드의 파일 이름 선언은 **스토리지 단일 지점**이 만든다. 원문
 *    `Content-Disposition` 을 넘기면 Flysystem 어댑터가 자기 `makeDisposition()` 을
 *    통째로 건너뛰어, 따옴표가 든 이름은 값이 첫 따옴표에서 끊기고 비-ASCII 이름은
 *    RFC 5987 확장 파라미터를 잃는다. 그 어댑터 보장 자체는 형제 모듈의 HTTP 계약
 *    테스트(`sirsoft-board` AttachmentPreviewCacheContractTest)가 재고, 여기서는
 *    "그 지점에 위임했는가" 를 잰다.
 * 2) 미리보기도 같다 — 원문 `Content-Disposition: inline` 은 그 이름 선언을 덮는다.
 *    `response()` 의 기본 disposition 이 이미 inline 이라 헤더를 넘길 이유가 없다.
 * 3) 서빙 디스크는 **행이 정한다.** 같은 클래스의 정리 경로는 이미 `storageForRow()`
 *    를 쓰는데 서빙만 주입 디스크로 가 있었다 — 디스크를 옮긴 사이트에서는 이 통로만
 *    전량 404 가 되고, 증상이 조용해 원인이 드러나지 않는다.
 */
class PageAttachmentServeContractTest extends ModuleTestCase
{
    /** 주입 스토리지가 쓰는 디스크 — 행 disk 와 같으면 그 인스턴스가 그대로 쓰인다. */
    private const INJECTED_DISK = 'local';

    /** 행에만 기록된, 주입 스토리지와 다른 디스크. */
    private const ROW_DISK = 'fake_cdn';

    private PageAttachmentService $service;

    /** @var MockInterface&StorageInterface */
    private $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // 고아 disk 폴백에 걸리지 않도록 행 disk 를 등록해 둔다.
        Config::set('filesystems.disks.'.self::ROW_DISK, [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
        ]);

        $this->storage = Mockery::mock(StorageInterface::class);
        $this->storage->shouldReceive('getDisk')->andReturn(self::INJECTED_DISK);

        // 서비스를 **직접 생성**한다 — `app()` 으로 받으면 mock 이 들어가지 않는다.
        //
        // `PageServiceProvider::$storageServices` 가 이 서비스에 대해 `StorageInterface`
        // 를 **컨텍스트 바인딩**으로 걸어 두는데, 컨텍스트 바인딩은 `app()->instance()`
        // 로 덮은 전역 바인딩보다 우선한다. 그래서 컨테이너 경유로 받으면 실제 드라이버가
        // 주입되고, `exists()` 가 false 라 서빙 메서드가 조용히 `null` 을 돌려준다 —
        // mock 의 기대는 하나도 불리지 않은 채 「응답이 null」로만 드러난다.
        $this->service = new PageAttachmentService(
            app(PageAttachmentRepositoryInterface::class),
            $this->storage,
            app(PageRepositoryInterface::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 저장하지 않은 첨부 행을 만듭니다 (서빙 경로는 DB 를 보지 않습니다).
     *
     * @param  string  $disk  행 disk
     * @param  string  $filename  원본 파일명
     * @param  string  $mimeType  MIME 타입
     * @return PageAttachment 첨부 행
     */
    private function makeRow(
        string $disk,
        string $filename = 'document.pdf',
        string $mimeType = 'application/pdf'
    ): PageAttachment {
        $attachment = new PageAttachment([
            'original_filename' => $filename,
            'stored_filename' => 'stored.bin',
            'disk' => $disk,
            'path' => '2026/08/28/stored.bin',
            'mime_type' => $mimeType,
            'size' => 11,
            'collection' => 'attachments',
        ]);
        $attachment->id = 1;

        return $attachment;
    }

    /**
     * 다운로드는 스토리지 `download()` 에 위임하고 `Content-Disposition` 을 넘기지 않는다.
     *
     * @scenario serving_type=download, published=published, viewer=guest
     *
     * @effects download_disposition_is_built_by_the_storage_layer
     */
    public function test_download_delegates_disposition_to_the_storage_layer(): void
    {
        $attachment = $this->makeRow(self::INJECTED_DISK);

        $this->storage->shouldReceive('exists')->once()->andReturn(true);

        // 원문 헤더로 자기 응답을 만들던 종전 경로는 `response()` 를 불렀다 —
        // 그 호출이 남아 있으면 이 mock 이 「예상치 못한 호출」로 실패한다.
        $this->storage->shouldReceive('download')
            ->once()
            ->withArgs(function ($category, $path, $filename, $headers) {
                return $category === 'attachments'
                    && $path === '2026/08/28/stored.bin'
                    && $filename === 'document.pdf'
                    && $headers['Content-Type'] === 'application/pdf'
                    && ! array_key_exists('Content-Disposition', $headers);
            })
            ->andReturn(new StreamedResponse);

        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($attachment));
    }

    /**
     * 다운로드 서빙 정보는 미리보기와 **같은 검증자 소스**를 싣는다 (15차 G3 라운드).
     *
     * 컨트롤러가 이 값을 코어 헬퍼에 넘겨 ETag·304 를 판정한다. 통로마다 사본으로 만들면
     * 같은 첨부가 통로에 따라 다른 ETag 를 갖는다.
     *
     * @scenario serving_type=download, published=published, viewer=guest
     *
     * @effects download_matching_etag_returns_304_with_the_cache_headers_of_200
     */
    public function test_download_info_shares_the_preview_validator_source(): void
    {
        $attachment = $this->makeRow(self::INJECTED_DISK);

        $this->storage->shouldReceive('exists')->once()->andReturn(true);
        $this->storage->shouldReceive('download')->once()->andReturn(new StreamedResponse);

        $info = $this->service->getDownloadInfo($attachment);
        $validators = $this->service->previewValidators($attachment);

        $this->assertNotNull($info);
        $this->assertInstanceOf(StreamedResponse::class, $info['response']);
        $this->assertSame($validators['etag_source'], $info['etag_source']);
        $this->assertSame($validators['last_modified'], $info['last_modified']);
        $this->assertSame('application/pdf', $info['mime_type']);
    }

    /**
     * 미리보기도 파일 이름 선언을 스토리지에 맡긴다 (원문 `inline` 헤더 금지).
     *
     * @scenario serving_type=preview, published=published, viewer=guest
     *
     * @effects preview_does_not_override_the_storage_filename_declaration
     */
    public function test_preview_does_not_override_the_storage_filename_declaration(): void
    {
        $attachment = $this->makeRow(self::INJECTED_DISK, 'photo.jpg', 'image/jpeg');

        $this->storage->shouldReceive('exists')->once()->andReturn(true);
        $this->storage->shouldReceive('response')
            ->once()
            ->withArgs(function ($category, $path, $filename, $headers) {
                return $category === 'attachments'
                    && $filename === 'photo.jpg'
                    && $headers['Content-Type'] === 'image/jpeg'
                    && ! array_key_exists('Content-Disposition', $headers);
            })
            ->andReturn(new StreamedResponse);

        $this->assertInstanceOf(StreamedResponse::class, $this->service->preview($attachment));
    }

    /**
     * 서빙(다운로드)은 행에 기록된 disk 의 스토리지를 쓴다.
     *
     * @scenario serving_type=download, published=published, viewer=guest
     *
     * @effects serving_follows_the_disk_recorded_on_the_row
     */
    public function test_download_serves_from_the_disk_recorded_on_the_row(): void
    {
        $attachment = $this->makeRow(self::ROW_DISK);

        $rowStorage = Mockery::mock(StorageInterface::class);
        $rowStorage->shouldReceive('exists')->once()->andReturn(true);
        $rowStorage->shouldReceive('download')->once()->andReturn(new StreamedResponse);

        $this->storage->shouldReceive('withDisk')
            ->once()
            ->with(self::ROW_DISK)
            ->andReturn($rowStorage);

        // 주입 스토리지로 새면 여기서 「예상치 못한 호출」로 실패한다.
        $this->assertInstanceOf(StreamedResponse::class, $this->service->download($attachment));
    }

    /**
     * 서빙(미리보기)도 같은 해석을 쓴다.
     *
     * @scenario serving_type=preview, published=published, viewer=guest
     *
     * @effects serving_follows_the_disk_recorded_on_the_row
     */
    public function test_preview_serves_from_the_disk_recorded_on_the_row(): void
    {
        $attachment = $this->makeRow(self::ROW_DISK, 'photo.jpg', 'image/jpeg');

        $rowStorage = Mockery::mock(StorageInterface::class);
        $rowStorage->shouldReceive('exists')->once()->andReturn(true);
        $rowStorage->shouldReceive('response')->once()->andReturn(new StreamedResponse);

        $this->storage->shouldReceive('withDisk')
            ->once()
            ->with(self::ROW_DISK)
            ->andReturn($rowStorage);

        $this->assertInstanceOf(StreamedResponse::class, $this->service->preview($attachment));
    }

    /**
     * 축소 경로를 지난 업로드도 행 `size` 가 **실제 저장 바이트**와 같다.
     *
     * 축소는 제자리 덮어쓰기이고 `ImageResizer::resizeInPlace()` 는 쓰기 전에
     * `is_file()` 로 그 경로를 stat 하므로, `clearstatcache()` 없이 읽는 `getSize()` 는
     * 축소 **전** 크기를 돌려준다. 그 값이 행에 남으면 서빙의 `Content-Length` 선언이
     * 실제 전송량보다 커져 응답이 잘린다 (11차 G3 라운드 B11-4 형제 축).
     *
     * 스토리지에 실제로 넘어간 바이트를 잡아 비교하므로 파일시스템에 의존하지 않는다.
     *
     * @effects recorded_size_matches_stored_bytes_after_the_resize_path
     */
    public function test_recorded_size_matches_stored_bytes_after_the_resize_path(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD 확장이 없어 축소 경로를 태울 수 없습니다');
        }

        Config::set('attachment.image_max_width', 32);
        Config::set('attachment.image_max_height', 32);

        $storedBytes = null;

        $this->storage->shouldReceive('put')
            ->once()
            ->andReturnUsing(function ($category, $path, $content) use (&$storedBytes) {
                $storedBytes = $content;

                return true;
            });

        // 상한을 크게 넘는 이미지 — 축소가 실제로 일어난다.
        $file = UploadedFile::fake()->image('big.jpg', 400, 400);

        $attachment = $this->service->upload($file, null, 'attachments', 'resize-contract');

        // 축소가 **실제로 일어났는지** 먼저 확인한다.
        $this->assertIsArray($attachment->meta, '이미지 메타가 기록되지 않았다');
        $this->assertLessThanOrEqual(
            32,
            $attachment->meta['width'],
            '축소가 일어나지 않았다 — 이 케이스가 축소 경로를 재지 못한다',
        );

        $this->assertSame(
            strlen((string) $storedBytes),
            (int) $attachment->size,
            '행의 size 가 실제 저장 바이트와 다르다 — 서빙의 Content-Length 선언이 응답을 자른다',
        );
    }
}
