<?php

namespace Tests\Unit\Helpers;

use App\Helpers\InlineMediaPolicy;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 업로드 파일 인라인 표시 정책 단위 테스트 (KVE-2026-2366)
 *
 * `image/` 접두사 판정은 image/svg+xml 을 이미지로 오인해 인라인 서빙했다.
 * 인라인 표시는 래스터 이미지로만 한정하고, 그 밖은 다운로드 + CSP 로 격리한다.
 */
class InlineMediaPolicyTest extends TestCase
{
    /**
     * 래스터 이미지만 인라인 대상이고 SVG·비이미지는 제외된다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=core_attachment
     *
     * @effects svg_is_not_treated_as_inline_image
     *
     * @return void 반환값 없음
     */
    public function test_only_raster_images_are_inline(): void
    {
        foreach (['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'IMAGE/PNG', 'image/png; charset=binary'] as $mime) {
            $this->assertTrue(InlineMediaPolicy::isInlineImage($mime), $mime.' 는 인라인 이미지여야 합니다.');
        }

        foreach (['image/svg+xml', 'image/svg', 'text/html', 'application/xml', 'application/pdf', '', null] as $mime) {
            $this->assertFalse(InlineMediaPolicy::isInlineImage($mime), var_export($mime, true).' 는 인라인 이미지가 아니어야 합니다.');
        }
    }

    /**
     * 래스터 이미지 응답은 인라인을 유지하고 nosniff 만 붙는다.
     *
     * @scenario entry_point=serving, content=real_png, surface=core_attachment
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_raster_response_keeps_inline_and_gets_nosniff(): void
    {
        $response = new Response('png', 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename=a.png']);

        InlineMediaPolicy::harden($response);

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('inline; filename=a.png', $response->headers->get('Content-Disposition'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * SVG 응답은 다운로드로 강제되고 CSP sandbox 로 격리되며 원래 파일명을 유지한다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=core_attachment
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    public function test_svg_response_is_forced_to_download_with_csp(): void
    {
        $response = new Response('<svg/>', 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'inline; filename=evil.png']);

        InlineMediaPolicy::harden($response);

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('evil.png', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 비 ASCII 파일명은 RFC 6266 filename* 로 보존된다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=core_attachment
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    public function test_non_ascii_filename_is_preserved(): void
    {
        $response = new Response('<svg/>', 200, ['Content-Type' => 'image/svg+xml']);

        InlineMediaPolicy::harden($response, null, '도면.svg');

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('도면.svg'), $disposition);
    }
}
