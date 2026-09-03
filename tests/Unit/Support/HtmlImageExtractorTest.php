<?php

namespace Tests\Unit\Support;

use App\Support\HtmlImageExtractor;
use Tests\TestCase;

/**
 * HtmlImageExtractor 추출/내부 판정 테스트 (공개 이슈 #22)
 *
 * 본문 HTML 의 첫 내부 이미지를 목록 썸네일·og:image 폴백 캐시로 쓰는 공용 계약을
 * 고정합니다. 내부/외부 판정은 TrustedScriptHosts::normalizeForOriginCheck (origin
 * 정규화 SSoT) 를 경유하므로, 브라우저가 외부 origin 으로 읽는 공격 문자열
 * (`/\/evil.com/x.jpg` 등)이 내부로 오판되면 비신뢰 호스트 이미지가 사이트 파생
 * 표면(목록·og:image)에 실립니다.
 */
class HtmlImageExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://shop.example.com']);
    }

    // ── candidates: src 수집 ──────────────────────────

    public function test_candidates_collects_srcs_in_document_order(): void
    {
        $html = '<p>a</p><img src="/one.jpg"><div><img src="/two.png"></div><img src="https://ext.com/three.gif">';

        $this->assertSame(
            ['/one.jpg', '/two.png', 'https://ext.com/three.gif'],
            HtmlImageExtractor::candidates($html)
        );
    }

    public function test_candidates_returns_empty_for_no_images(): void
    {
        $this->assertSame([], HtmlImageExtractor::candidates('<p>텍스트만 있는 본문</p>'));
        $this->assertSame([], HtmlImageExtractor::candidates(''));
        $this->assertSame([], HtmlImageExtractor::candidates('   '));
    }

    public function test_candidates_skips_empty_src(): void
    {
        $this->assertSame(
            ['/real.jpg'],
            HtmlImageExtractor::candidates('<img src=""><img><img src="   "><img src="/real.jpg">')
        );
    }

    public function test_candidates_survives_broken_html(): void
    {
        // 미닫힘 태그 + 중첩 오류 — DOM 파서 관용 파싱으로 예외 없이 처리돼야 한다
        $this->assertIsArray(HtmlImageExtractor::candidates('<div><p><img src="/broken.jpg"<span>텍스트</div>'));

        // 미닫힘이어도 정상 형태의 img 는 수집된다
        $this->assertSame(
            ['/ok.jpg'],
            HtmlImageExtractor::candidates('<div><img src="/ok.jpg"><p>미닫힘 문단</div>')
        );
    }

    public function test_candidates_safe_on_plain_text(): void
    {
        $this->assertSame([], HtmlImageExtractor::candidates('그냥 평문입니다. <img 처럼 보이는 텍스트'));
    }

    public function test_candidates_handles_single_quoted_attributes(): void
    {
        $this->assertSame(
            ['/single.jpg', '/double.jpg'],
            HtmlImageExtractor::candidates("<img src='/single.jpg'><img src=\"/double.jpg\">")
        );
    }

    // ── firstInternal: 내부 판정 ──────────────────────────

    /**
     * @effects content_internal_image_fills_list_thumbnail
     */
    public function test_relative_path_passes(): void
    {
        $this->assertSame(
            '/storage/uploads/a.jpg',
            HtmlImageExtractor::firstInternal('<img src="/storage/uploads/a.jpg">')
        );
    }

    public function test_absolute_same_host_converted_to_relative(): void
    {
        $this->assertSame(
            '/storage/uploads/a.jpg?v=1',
            HtmlImageExtractor::firstInternal('<img src="https://shop.example.com/storage/uploads/a.jpg?v=1">')
        );
    }

    public function test_absolute_same_host_case_insensitive(): void
    {
        $this->assertSame(
            '/img/a.png',
            HtmlImageExtractor::firstInternal('<img src="https://SHOP.Example.COM/img/a.png">')
        );
    }

    /**
     * @effects external_only_content_yields_null
     */
    public function test_external_host_excluded(): void
    {
        $this->assertNull(
            HtmlImageExtractor::firstInternal('<img src="https://evil.com/x.jpg">')
        );
    }

    public function test_protocol_relative_excluded(): void
    {
        $this->assertNull(
            HtmlImageExtractor::firstInternal('<img src="//evil.com/x.jpg">')
        );
    }

    /**
     * 정규화 공격 문자열 — 문자열상 path 지만 브라우저는 외부 origin 으로 읽는 형태.
     * 정규화 SSoT 를 거치지 않으면 내부로 오판된다.
     */
    public function test_normalization_attack_strings_judged_external(): void
    {
        $attacks = [
            '/\\/evil.com/x.jpg',
            '\\\\evil.com\\x.jpg',
            "/\t/evil.com/x.jpg",
            '///evil.com/x.jpg',
        ];

        foreach ($attacks as $attack) {
            $this->assertNull(
                HtmlImageExtractor::firstInternal('<img src="'.htmlspecialchars($attack, ENT_QUOTES).'">'),
                "외부 판정 실패: {$attack}"
            );
        }
    }

    public function test_non_http_schemes_excluded(): void
    {
        $this->assertNull(HtmlImageExtractor::firstInternal('<img src="data:image/png;base64,AAAA">'));
        $this->assertNull(HtmlImageExtractor::firstInternal('<img src="javascript:alert(1)">'));
        $this->assertNull(HtmlImageExtractor::firstInternal('<img src="blob:https://shop.example.com/uuid">'));
        $this->assertNull(HtmlImageExtractor::firstInternal('<img src="ftp://shop.example.com/a.jpg">'));
    }

    public function test_first_external_then_internal_picks_internal(): void
    {
        $html = '<img src="https://evil.com/first.jpg"><img src="/second.jpg">';

        $this->assertSame('/second.jpg', HtmlImageExtractor::firstInternal($html));
    }

    public function test_no_image_returns_null(): void
    {
        $this->assertNull(HtmlImageExtractor::firstInternal('<p>이미지 없음</p>'));
        $this->assertNull(HtmlImageExtractor::firstInternal(''));
    }

    public function test_extra_allowed_prefix_passes_with_original_form(): void
    {
        $html = '<img src="https://cdn.example.net/bucket/a.jpg">';

        $this->assertNull(HtmlImageExtractor::firstInternal($html));
        $this->assertSame(
            'https://cdn.example.net/bucket/a.jpg',
            HtmlImageExtractor::firstInternal($html, ['https://cdn.example.net/'])
        );
    }

    public function test_extra_prefix_does_not_rescue_unlisted_host(): void
    {
        $this->assertNull(
            HtmlImageExtractor::firstInternal(
                '<img src="https://evil.com/a.jpg">',
                ['https://cdn.example.net/']
            )
        );
    }

    public function test_url_over_1000_chars_excluded(): void
    {
        $longUrl = '/storage/'.str_repeat('a', 1000).'.jpg';
        $html = '<img src="'.$longUrl.'"><img src="/short.jpg">';

        $this->assertSame('/short.jpg', HtmlImageExtractor::firstInternal($html));
    }

    public function test_absolute_same_host_without_path_returns_root(): void
    {
        $this->assertSame(
            '/',
            HtmlImageExtractor::firstInternal('<img src="https://shop.example.com">')
        );
    }
}
