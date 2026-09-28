<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Models;

use App\Contracts\Extension\StorageInterface;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 첨부 인라인 이미지 판정 회귀 테스트 (KVE-2026-2366 전수 T-B)
 *
 * 페이지 첨부 미리보기는 isImage() 로 인라인 서빙 여부를 가른다. 운영자가 허용 MIME 에
 * image/svg+xml 을 추가해 SVG 가 저장돼도 미리보기(인라인) 대상이 되면 안 된다.
 */
class PageAttachmentInlineImageTest extends ModuleTestCase
{
    /**
     * 래스터 이미지만 이미지로 판정하고 SVG 는 제외한다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=page_attachment
     *
     * @effects svg_is_not_treated_as_inline_image
     *
     * @return void 반환값 없음
     */
    public function test_svg_is_not_an_inline_image(): void
    {
        $this->assertFalse((new PageAttachment(['mime_type' => 'image/svg+xml']))->isImage());
    }

    /**
     * 래스터 이미지는 종전대로 이미지로 판정된다 (미리보기 회귀 가드).
     *
     * @scenario entry_point=serving, content=real_png, surface=page_attachment
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_raster_is_an_inline_image(): void
    {
        $this->assertTrue((new PageAttachment(['mime_type' => 'image/png']))->isImage());
        $this->assertTrue((new PageAttachment(['mime_type' => 'image/webp']))->isImage());
    }

    /**
     * 미리보기 서비스는 SVG 첨부에 인라인 스트림을 만들지 않는다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=page_attachment
     *
     * @effects svg_is_not_treated_as_inline_image
     *
     * @return void 반환값 없음
     */
    public function test_preview_refuses_svg(): void
    {
        $attachment = new PageAttachment([
            'mime_type' => 'image/svg+xml',
            'path' => 'kve/evil.png',
            'original_filename' => 'evil.png',
        ]);

        $this->assertNull(app(PageAttachmentService::class)->preview($attachment));
    }

    /**
     * 다운로드 서비스는 SVG 를 다운로드로 강제하고 nosniff·CSP 를 붙인다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=page_attachment
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    public function test_download_hardens_svg(): void
    {
        $attachment = $this->storedAttachment('kve-inline/evil.png', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $response = app(PageAttachmentService::class)->download($attachment);

        $this->assertNotNull($response);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 미리보기 서비스는 래스터 이미지를 인라인으로 내보내며 nosniff 를 붙인다.
     *
     * @scenario entry_point=serving, content=real_png, surface=page_attachment
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_preview_hardens_raster(): void
    {
        $attachment = $this->storedAttachment('kve-inline/real.png', 'image/png', 'png-bytes');

        $response = app(PageAttachmentService::class)->preview($attachment);

        $this->assertNotNull($response);
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * 서비스 스토리지에 파일을 두고 (저장하지 않은) 첨부 모델을 만듭니다.
     *
     * @param  string  $path  저장 경로
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return PageAttachment 첨부 모델
     */
    private function storedAttachment(string $path, string $mime, string $content): PageAttachment
    {
        $this->pageStorage()->put('attachments', $path, $content);

        return new PageAttachment([
            'mime_type' => $mime,
            'path' => $path,
            'original_filename' => basename($path),
        ]);
    }

    /**
     * 페이지 첨부 서비스가 쓰는 모듈 스토리지 드라이버를 꺼냅니다.
     *
     * @return StorageInterface 모듈 스토리지
     */
    private function pageStorage(): StorageInterface
    {
        $property = (new \ReflectionClass(PageAttachmentService::class))->getProperty('storage');

        return $property->getValue(app(PageAttachmentService::class));
    }

    /**
     * 테스트가 만든 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->pageStorage()->deleteDirectory('attachments', 'kve-inline');

        parent::tearDown();
    }
}
