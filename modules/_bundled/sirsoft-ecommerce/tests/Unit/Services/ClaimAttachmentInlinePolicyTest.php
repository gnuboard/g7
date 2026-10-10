<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\Storage\ModuleStorageDriver;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 첨부 사진 서빙 인라인 정책 회귀 테스트 (KVE-2026-2366 서빙 축)
 *
 * 교환·반품·환불 신청서의 첨부 사진 보기(`ClaimAttachmentService::download`)는 다른 이미지 서빙
 * 지점과 같은 InlineMediaPolicy::harden 을 거쳐야 한다. 업로드가 래스터만 받더라도 서빙 계층은
 * 저장된 MIME 과 무관하게 래스터만 인라인으로 내보내고 모든 응답에 nosniff 를 붙인다.
 * 이 서빙 지점은 KVE-2026-2366 수정보다 뒤에 생겨 그 전수 조사(T-B)에 들지 않았다.
 */
class ClaimAttachmentInlinePolicyTest extends ModuleTestCase
{
    private ClaimAttachmentService $service;

    /**
     * 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ClaimAttachmentService::class);
    }

    /**
     * 저장된 SVG 첨부는 다운로드로 강제되고 nosniff·CSP 가 붙는다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=ecommerce_image
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function stored_svg_claim_image_is_served_as_download(): void
    {
        $image = $this->makeRow('claims/kve-inline/evil.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $response = $this->service->download($image->hash, signatureVerified: true);

        $this->assertNotNull($response);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 래스터 첨부는 인라인을 유지하고 nosniff 가 붙는다 (신청서 사진 미리보기 회귀 가드).
     *
     * @scenario entry_point=serving, content=real_png, surface=ecommerce_image
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function raster_claim_image_stays_inline(): void
    {
        $image = $this->makeRow('claims/kve-inline/real.jpg', 'image/jpeg', 'jpeg-bytes');

        $response = $this->service->download($image->hash, signatureVerified: true);

        $this->assertNotNull($response);
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * 테스트용 첨부 파일과 행을 만듭니다.
     *
     * @param  string  $path  저장 경로
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return ClaimImage 첨부 행
     */
    private function makeRow(string $path, string $mime, string $content): ClaimImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->put('images', $path, $content);

        return ClaimImage::factory()->create([
            'disk' => 'modules',
            'path' => $path,
            'original_name' => basename($path),
            'mime_type' => $mime,
            'size' => strlen($content),
        ]);
    }

    /**
     * 테스트 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->deleteDirectory('images', 'claims/kve-inline');

        parent::tearDown();
    }
}
