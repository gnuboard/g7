<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\TemplateLayoutAttachmentRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Services\TemplateLayoutAttachmentService;
use Mockery;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * 템플릿 레이아웃 첨부 디스크 인지 서빙 테스트 (공개 #99 / 내부 #563)
 *
 * PublicAttachmentController 이미지 서빙과 동형 결함 — getServableFilePath 가
 * getBasePath 로 로컬 절대 경로를 조립해 S3 디스크 행에서 fileResponse(filemtime)가
 * 500 이 나던 구조의 회귀 가드. 서빙은 StorageInterface::response() 스트림으로
 * 디스크 무관하게 성립해야 한다.
 */
class TemplateLayoutAttachmentServingTest extends TestCase
{
    private TemplateLayoutAttachmentService $service;

    private $templateRepository;

    private $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Mockery::mock(TemplateLayoutAttachmentRepositoryInterface::class);
        $this->templateRepository = Mockery::mock(TemplateRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);

        $this->service = new TemplateLayoutAttachmentService(
            $repository,
            $this->templateRepository,
            $this->storage
        );
    }

    /**
     * 소속 템플릿 첨부를 만듭니다 (DB 미저장).
     */
    private function makeAttachment(int $templateId, string $disk = 's3'): TemplateLayoutAttachment
    {
        $attachment = new TemplateLayoutAttachment([
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => 'sirsoft-basic/home/bg.png',
            'original_name' => 'bg.png',
            'mime_type' => 'image/png',
            'size' => 70,
        ]);
        $attachment->template_id = $templateId;

        return $attachment;
    }

    /**
     * s3 디스크 행이 StorageInterface::response() 스트림으로 서빙 정보를 반환해야 합니다.
     *
     * @scenario region_shape=aws_region,endpoint=absent,driver_usability=adapter_present,attachment_disk=follow_s3
     *
     * @effects attachment_upload_follows_driver
     */
    public function test_s3_disk_attachment_returns_streamed_response(): void
    {
        $template = new Template;
        $template->id = 7;
        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('sirsoft-basic')->andReturn($template);

        $stream = new StreamedResponse(function () {
            echo 'bytes';
        }, 200, ['Content-Type' => 'image/png']);

        // 행 disk 해석은 주입 disk 와의 비교부터 한다 (`ResolvesRowStorage`).
        $this->storage->shouldReceive('getDisk')->andReturn('public');
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->storage->shouldReceive('withDisk')->with('s3')->andReturnSelf();
        $this->storage->shouldReceive('response')->andReturn($stream);

        $result = $this->service->getServableResponse('sirsoft-basic', $this->makeAttachment(7));

        $this->assertNotNull($result);
        $this->assertSame($stream, $result['response']);
        $this->assertNotEmpty($result['etag_source']);
    }

    /**
     * 경로 템플릿과 첨부 소속이 다르면 null 이어야 합니다 (교차 템플릿 접근 차단 유지).
     */
    public function test_cross_template_access_returns_null(): void
    {
        $template = new Template;
        $template->id = 7;
        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('sirsoft-basic')->andReturn($template);

        $result = $this->service->getServableResponse('sirsoft-basic', $this->makeAttachment(99));

        $this->assertNull($result);
    }

    /**
     * 행 disk 가 **더 이상 등록돼 있지 않으면** 주입 스토리지로 폴백한다.
     *
     * 고아 disk 는 운영자가 만들지 않아도 생긴다 — 그 디스크를 제공하던 플러그인을
     * 비활성화하거나 `FILESYSTEM_DISK` 를 바꾸면 config 에서 사라지고 행은 남는다.
     * 종전에는 그 값을 존재 검증 없이 `withDisk()` 에 넘겼는데, `withDisk()` 는 clone
     * 후 대입만 할 뿐 검증하지 않으므로 예외는 그 뒤 `response()` 가 디스크를 해석하는
     * 순간 터진다. 이 서비스가 소비하는 라우트는 **무인증 공개 서빙**이라 고아 disk 행
     * 하나로 500 + 스택 로그가 났다. 같은 판정을 번들 확장 넷은 이미 갖고 있었고
     * 코어 둘만 없었다 (12차 G3 라운드 B12-1).
     *
     * 폴백 결과는 그 디스크에 파일이 없으므로 404 다 — 그것이 「저장 위치를 잃은
     * 첨부」의 정상 degradation 이고, 500 은 아니다.
     */
    public function test_orphan_row_disk_falls_back_instead_of_throwing(): void
    {
        $template = new Template;
        $template->id = 7;
        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('sirsoft-basic')->andReturn($template);

        // 행은 s3 를 가리키는데 그 disk 가 config 에서 사라진 상태.
        config(['filesystems.disks.s3' => null]);

        $this->storage->shouldReceive('getDisk')->andReturn('public');
        // 고아 disk 로는 withDisk 를 만들지 않아야 한다 — 만들면 아래 response 해석에서 터진다.
        $this->storage->shouldNotReceive('withDisk');
        $this->storage->shouldReceive('response')->andReturnNull();

        $result = $this->service->getServableResponse('sirsoft-basic', $this->makeAttachment(7));

        $this->assertNull($result, '고아 disk 는 404 로 떨어져야 한다 (500 아님)');
    }

    /**
     * 스토리지에 파일이 없으면(response null) null 이어야 합니다.
     */
    public function test_missing_file_returns_null(): void
    {
        $template = new Template;
        $template->id = 7;
        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('sirsoft-basic')->andReturn($template);

        // 행 disk 해석은 주입 disk 와의 비교부터 한다 (`ResolvesRowStorage`).
        $this->storage->shouldReceive('getDisk')->andReturn('public');
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->storage->shouldReceive('withDisk')->with('s3')->andReturnSelf();
        $this->storage->shouldReceive('response')->andReturnNull();

        $result = $this->service->getServableResponse('sirsoft-basic', $this->makeAttachment(7));

        $this->assertNull($result);
    }
}
