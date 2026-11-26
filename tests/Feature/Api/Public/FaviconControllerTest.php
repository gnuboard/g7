<?php

namespace Tests\Feature\Api\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 파비콘 공개 서빙 테스트
 */
class FaviconControllerTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 형식별 Content-Type 정규화, nosniff, inline, 1년 immutable 캐시.
     *
     * @scenario surface=api
     *
     * @effects highest_valid_tier_wins
     *
     * @return void 반환값 없음
     */
    public function test_serves_favicon_with_normalized_type_and_long_cache(): void
    {
        // 장기 캐시 헤더는 운영 환경에서만 붙는다(파일 응답 공통 규칙 — 그 밖은 no-cache)
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->assertFaviconResponses();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    /**
     * 형식별 응답 헤더를 확인합니다.
     *
     * @return void 반환값 없음
     */
    private function assertFaviconResponses(): void
    {
        foreach ([
            ['image/vnd.microsoft.icon', 'image/x-icon'],
            ['image/png', 'image/png'],
            ['image/gif', 'image/gif'],
            ['image/jpeg', 'image/jpeg'],
            ['image/webp', 'image/webp'],
        ] as [$stored, $expected]) {
            $attachment = $this->makeFaviconAttachment('site_favicon', $stored);

            $response = $this->get('/api/favicon/'.$attachment->hash)->assertOk();

            $this->assertSame($expected, $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        }
    }

    /**
     * svg 는 스크립트·외부 자원을 막는 CSP 와 `X-Content-Type-Options: nosniff` 를 함께 단다.
     *
     * @scenario surface=api
     *
     * @effects svg_served_with_restrictive_csp
     *
     * @return void 반환값 없음
     */
    public function test_svg_favicon_has_restrictive_csp(): void
    {
        $svg = $this->makeFaviconAttachment('template_settings.qa434-any.branding.favicon', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');

        $response = $this->get('/api/favicon/'.$svg->hash)->assertOk();

        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'svg 도 내용 추측을 막는다');
    }

    /**
     * 파비콘 컬렉션이 아닌 첨부·목록 밖 MIME·없는 해시는 404 다(일반 다운로드 권한 판정을 우회하지 않게).
     *
     * @scenario surface=api
     *
     * @effects non_favicon_collection_404
     *
     * @return void 반환값 없음
     */
    public function test_non_favicon_attachments_are_not_served(): void
    {
        $logo = $this->makeFaviconAttachment('site_logo', 'image/png');
        $pdf = $this->makeFaviconAttachment('site_favicon', 'application/pdf');

        $this->get('/api/favicon/'.$logo->hash)->assertNotFound();
        $this->get('/api/favicon/'.$pdf->hash)->assertNotFound();
        $this->get('/api/favicon/AAAAAAAAAAAA')->assertNotFound();
    }
}
