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
     * 스토리지에 파일이 없으면(response null) null 이어야 합니다.
     */
    public function test_missing_file_returns_null(): void
    {
        $template = new Template;
        $template->id = 7;
        $this->templateRepository->shouldReceive('findByIdentifier')
            ->with('sirsoft-basic')->andReturn($template);

        $this->storage->shouldReceive('withDisk')->with('s3')->andReturnSelf();
        $this->storage->shouldReceive('response')->andReturnNull();

        $result = $this->service->getServableResponse('sirsoft-basic', $this->makeAttachment(7));

        $this->assertNull($result);
    }
}
