<?php

namespace Tests\Unit\Support;

use App\Support\AssetCssUrlRewriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `AssetCssUrlRewriter` — CSS 안 상대 참조의 절대 URL 치환 규칙.
 *
 * 이 축이 깨지면 증상은 404 하나뿐이다 — 글꼴이 기본 서체로 대체되고 아이콘이 빈칸이 되며
 * 서버 로그에는 정상 요청으로 남는다. 그래서 해석 규칙(상대·상위·따옴표·비대상 판정)을
 * 항목별로 고정한다.
 */
class AssetCssUrlRewriterTest extends TestCase
{
    /** 테스트용 URL 생성기 — 확장 기준 경로를 그대로 드러내 해석 결과를 검증 가능하게 한다 */
    private static function urlFor(): callable
    {
        return static fn (string $path): string => '/ASSET/'.$path;
    }

    /**
     * 상대 참조는 CSS 가 놓인 디렉토리 기준으로 해석된다.
     *
     * @param  string  $ref  CSS 안의 원본 참조
     * @param  string  $expected  기대되는 확장 기준 경로
     */
    #[DataProvider('relativeReferenceProvider')]
    public function test_relative_references_resolve_against_the_css_directory(string $ref, string $expected): void
    {
        $css = "a{background:url('{$ref}')}";

        $result = AssetCssUrlRewriter::rewrite($css, 'vendor/pkg/1.0/css/style.css', self::urlFor());

        $this->assertStringContainsString('/ASSET/'.$expected, $result, "참조 '{$ref}' 해석이 어긋났습니다.");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function relativeReferenceProvider(): array
    {
        return [
            '명시적 현재 디렉토리' => ['./a.woff2', 'vendor/pkg/1.0/css/a.woff2'],
            '암묵적 현재 디렉토리' => ['a.woff2', 'vendor/pkg/1.0/css/a.woff2'],
            '하위 디렉토리' => ['./woff2/a.woff2', 'vendor/pkg/1.0/css/woff2/a.woff2'],
            '상위 한 단계' => ['../flags/kr.svg', 'vendor/pkg/1.0/flags/kr.svg'],
            '상위 두 단계' => ['../../shared/a.svg', 'vendor/pkg/shared/a.svg'],
            '중간에 현재 표기 혼합' => ['./../flags/./kr.svg', 'vendor/pkg/1.0/flags/kr.svg'],
        ];
    }

    /**
     * 따옴표 3종(없음·홑·겹)을 모두 인식한다.
     *
     * 따옴표가 없던 참조는 겹따옴표로 감싸 내보낸다 — 생성 URL 이 `?`·`&` 를 포함할 수 있는데
     * 따옴표 없는 `url()` 토큰에서 그 문자들은 CSS 문법상 허용되지 않는다.
     */
    public function test_all_quote_forms_are_recognized_and_output_is_quoted(): void
    {
        $css = 'a{background:url(x.svg)}b{background:url("x.svg")}c{background:url(\'x.svg\')}';

        $result = AssetCssUrlRewriter::rewrite($css, 'css/style.css', self::urlFor());

        $this->assertSame(3, substr_count($result, '/ASSET/css/x.svg'), '세 형태 모두 치환되어야 합니다.');
        $this->assertStringNotContainsString('url(/ASSET', $result, '따옴표 없는 출력이 남아 있습니다.');
    }

    /**
     * 절대 참조·스킴 참조는 대상이 아니다.
     *
     * @param  string  $ref  건드리면 안 되는 참조
     */
    #[DataProvider('untouchedReferenceProvider')]
    public function test_absolute_and_scheme_references_are_left_alone(string $ref): void
    {
        $css = "a{background:url('{$ref}')}";

        $result = AssetCssUrlRewriter::rewrite($css, 'css/style.css', self::urlFor());

        $this->assertStringNotContainsString('/ASSET/', $result, "참조 '{$ref}' 는 치환 대상이 아닙니다.");
        $this->assertStringContainsString($ref, $result, "참조 '{$ref}' 원문이 보존되어야 합니다.");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function untouchedReferenceProvider(): array
    {
        return [
            '루트 상대' => ['/build/ext/1/a.woff2'],
            '프로토콜 상대' => ['//cdn.example.com/a.woff2'],
            'https 절대' => ['https://cdn.example.com/a.woff2'],
            'data URI' => ['data:font/woff2;base64,AAAA'],
            '프래그먼트 전용' => ['#gradient'],
        ];
    }

    /**
     * 프래그먼트는 보존하고, 참조 자신의 쿼리는 버린다.
     *
     * 생성되는 자산 URL 이 자기 캐시 버전 쿼리를 갖기 때문이다 — 원본 쿼리를 함께 남기면
     * 두 쿼리가 겹쳐 서빙 계층이 파일 경로를 잘못 읽는다.
     */
    public function test_fragment_is_preserved_and_reference_query_is_dropped(): void
    {
        $css = "a{src:url('./f.woff2?v=9#iefix')}";

        $result = AssetCssUrlRewriter::rewrite($css, 'css/style.css', self::urlFor());

        $this->assertStringContainsString('/ASSET/css/f.woff2#iefix', $result);
        $this->assertStringNotContainsString('v=9', $result);
    }

    /**
     * `@import "..."` 문자열 형태도 치환된다.
     */
    public function test_bare_import_strings_are_rewritten(): void
    {
        $css = '@import "./base.css"; a{color:red}';

        $result = AssetCssUrlRewriter::rewrite($css, 'css/style.css', self::urlFor());

        $this->assertStringContainsString('@import "/ASSET/css/base.css"', $result);
    }

    /**
     * CSS 가 루트에 있으면 상대 참조는 루트 기준으로 해석된다.
     */
    public function test_root_level_css_resolves_from_the_extension_root(): void
    {
        $css = "a{background:url('img/a.svg')}";

        $result = AssetCssUrlRewriter::rewrite($css, 'style.css', self::urlFor());

        $this->assertStringContainsString('/ASSET/img/a.svg', $result);
    }

    /**
     * 치환 대상이 없는 CSS 는 한 글자도 바뀌지 않는다.
     *
     * 이 단언이 없으면 정규식이 본문을 건드리는 회귀가 조용히 통과한다.
     */
    public function test_css_without_relative_references_is_returned_unchanged(): void
    {
        $css = "a{color:red}\n/* url('should-not-match-in-comment') is prose */\nb{content:'x'}";

        // 주석 안의 참조는 치환된다(CSS 파서가 아니라 텍스트 치환이므로) — 그 사실을 포함해
        // 참조가 정말 하나도 없는 입력으로 불변을 확인한다.
        $plain = 'a{color:red}b{content:"x"}@media (min-width:1px){c{display:none}}';

        $this->assertSame($plain, AssetCssUrlRewriter::rewrite($plain, 'css/style.css', self::urlFor()));
        $this->assertNotSame('', AssetCssUrlRewriter::rewrite($css, 'css/style.css', self::urlFor()));
    }

    /**
     * 상위 참조가 확장 루트를 넘어가도 루트 밖으로 나가지 않는다.
     *
     * 넘어간 만큼은 소진되고 루트 기준으로 정착한다 — 서빙 계층의 경로 검증에 그대로 걸리도록
     * 두는 것이 목적이며, 여기서 `../` 를 URL 에 실어 보내면 안 된다.
     */
    public function test_upward_traversal_cannot_escape_the_extension_root(): void
    {
        $css = "a{background:url('../../../../../../etc/passwd')}";

        $result = AssetCssUrlRewriter::rewrite($css, 'css/style.css', self::urlFor());

        $this->assertStringContainsString('/ASSET/etc/passwd', $result);
        $this->assertStringNotContainsString('..', $result, '상위 참조가 URL 에 그대로 실렸습니다.');
    }
}
