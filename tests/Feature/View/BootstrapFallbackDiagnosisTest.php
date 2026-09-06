<?php

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * 부팅 실패 사유별 안내 분기 (공개 #121).
 *
 * 번들을 받았는데 실행되지 않는 경우와 끝내 받지 못한 경우는 사용자가 취할 행동이
 * 정반대다. 전자에 "네트워크가 불안정하다 / 새로고침하라" 를 띄우면 새로고침해도
 * 낫지 않으므로 사용자는 자기 회선을 탓하게 된다.
 *
 * 인라인 부트스트랩은 코어 번들이 없어도 동작해야 하므로 ES5 로만 작성한다 —
 * 화살표 함수·const·템플릿 리터럴·옵셔널 체이닝이 섞이면 이 계층까지 구형
 * 브라우저에서 죽어 안내조차 뜨지 않는다.
 *
 * @scenario entrypoint=user, failure_mode=parse_error
 *
 * @effects bootstrap_partial_emits_reason_branch, incompatible_strings_defined_per_locale
 */
class BootstrapFallbackDiagnosisTest extends TestCase
{
    /**
     * 부트스트랩 partial 을 렌더합니다.
     *
     * @param  string  $templateType  'user' 또는 'admin'
     * @return string 렌더된 HTML
     */
    private function render(string $templateType = 'user'): string
    {
        return view('partials.bootstrap-scripts', [
            'templateType' => $templateType,
            'coreEngineSrc' => '/build/core/template-engine.min.js',
            'componentsSrc' => '/api/templates/assets/sirsoft-basic/js/components.iife.js',
            'initConfig' => ['templateId' => 'sirsoft-basic', 'locale' => 'ko'],
        ])->render();
    }

    public function test_두_진입점_모두_사유별_분기를_렌더한다(): void
    {
        foreach (['user', 'admin'] as $templateType) {
            $html = $this->render($templateType);

            $this->assertStringContainsString(
                "? 'incompatible' : 'corrupt'",
                $html,
                "[{$templateType}] 전역 부재 경로가 SyntaxError 관측 결과로 사유를 가르지 않는다"
            );
            $this->assertStringContainsString(
                "renderFallback(blocked ? 'blocked' : 'network')",
                $html,
                "[{$templateType}] 재시도 소진 경로가 차단/네트워크 사유를 가르지 않는다"
            );
            $this->assertStringContainsString(
                'data-g7-bootstrap-fallback',
                $html,
                "[{$templateType}] 폴백 사유 마커가 없다"
            );
        }
    }

    /**
     * HTTPS 페이지가 http 자산을 요청해 차단된 경우를 별도 사유로 가른다 (#124).
     *
     * 이 실패는 회선이 멀쩡하고 새로고침으로도 낫지 않으므로, 네트워크 문구 + 새로고침
     * 버튼은 두 겹의 거짓 안내가 된다.
     *
     * @effects blocked_branch_is_emitted_with_https_http_judgment
     */
    public function test_혼합_콘텐츠_차단을_별도_사유로_가른다(): void
    {
        foreach (['user', 'admin'] as $templateType) {
            $html = $this->render($templateType);

            $this->assertStringContainsString(
                "location.protocol === 'https:'",
                $html,
                "[{$templateType}] 차단 판정이 페이지 스킴을 보지 않는다"
            );
            $this->assertStringContainsString(
                "indexOf('http://') === 0",
                $html,
                "[{$templateType}] 차단 판정이 자산 스킴을 보지 않는다"
            );
            $this->assertStringContainsString(
                "blocked ? 'blocked' : 'network'",
                $html,
                "[{$templateType}] 폴백 마커가 blocked 값을 낼 수 없다"
            );
            $this->assertStringContainsString(
                '(incompatible || blocked)',
                $html,
                "[{$templateType}] 차단 경로에서 새로고침 버튼이 생략되지 않는다"
            );
        }
    }

    /**
     * 차단 안내는 화면(방문자)과 콘솔(운영자)로 대상을 나눈다 (#124).
     *
     * 화면 문구에 서버 설정 지시를 쓰면 방문자에게 실행 불가능한 지시를 주는 셈이고,
     * 원인을 통째로 감추면 운영자가 도달할 통로가 사라진다.
     *
     * @effects blocked_notice_splits_visitor_screen_and_operator_console
     */
    public function test_차단_안내는_화면과_콘솔로_대상을_나눈다(): void
    {
        $html = $this->render();

        // 콘솔에는 운영자 기준 원인·조치가 영문으로 남는다 (기존 로그와 같은 계열).
        $this->assertStringContainsString('Blocked as mixed content', $html);
        $this->assertStringContainsString('TRUSTED_PROXIES', $html);
        $this->assertStringContainsString('docs/backend/reverse-proxy.md', $html);

        $original = app()->getLocale();

        try {
            foreach (['ko', 'en'] as $locale) {
                app()->setLocale($locale);

                foreach (['blocked_title', 'blocked_message'] as $key) {
                    $value = __("errors.bootstrap.{$key}");

                    $this->assertNotSame("errors.bootstrap.{$key}", $value, "[{$locale}] errors.bootstrap.{$key} 미정의");
                    $this->assertNotSame('', trim($value), "[{$locale}] errors.bootstrap.{$key} 가 비어 있다");
                }

                // 사유가 다르면 문구도 달라야 한다 — 같으면 분기가 무의미하다.
                $this->assertNotSame(
                    __('errors.bootstrap.message'),
                    __('errors.bootstrap.blocked_message'),
                    "[{$locale}] 차단 문구가 네트워크 문구와 동일하다"
                );

                // 화면 문구는 방문자 기준 — 서버 설정 지시를 담지 않는다.
                $this->assertStringNotContainsString(
                    'TRUSTED_PROXIES',
                    __('errors.bootstrap.blocked_message'),
                    "[{$locale}] 방문자 화면에 서버 설정 지시가 노출된다"
                );
                $this->assertStringNotContainsString(
                    '.env',
                    __('errors.bootstrap.blocked_message'),
                    "[{$locale}] 방문자 화면에 서버 설정 지시가 노출된다"
                );
            }
        } finally {
            app()->setLocale($original);
        }
    }

    public function test_파싱_오류_관측_리스너가_설치된다(): void
    {
        $html = $this->render();

        // 파싱 실패는 <script> 의 error 가 아니라 load 를 발생시키므로 element
        // 이벤트로는 구분할 수 없다. window 의 error 이벤트로만 관측된다.
        $this->assertStringContainsString('bundleSrcs', $html);
        $this->assertStringContainsString('SyntaxError', $html);
        $this->assertStringContainsString('syntaxError', $html);
    }

    public function test_비호환_안내_문구가_지원_로케일마다_정의된다(): void
    {
        $original = app()->getLocale();

        try {
            foreach (['ko', 'en'] as $locale) {
                app()->setLocale($locale);

                foreach (['incompatible_title', 'incompatible_message'] as $key) {
                    $value = __("errors.bootstrap.{$key}");

                    $this->assertNotSame(
                        "errors.bootstrap.{$key}",
                        $value,
                        "[{$locale}] errors.bootstrap.{$key} 미정의 — 화면에 키 문자열이 그대로 노출된다"
                    );
                    $this->assertNotSame('', trim($value), "[{$locale}] errors.bootstrap.{$key} 가 비어 있다");
                }

                // 사유가 다르면 문구도 달라야 한다 — 같으면 분기가 무의미하다
                $this->assertNotSame(
                    __('errors.bootstrap.message'),
                    __('errors.bootstrap.incompatible_message'),
                    "[{$locale}] 비호환 문구가 네트워크 문구와 동일하다"
                );
            }
        } finally {
            app()->setLocale($original);
        }
    }

    public function test_번들_일본어_언어팩에도_비호환_문구가_있다(): void
    {
        $path = base_path('lang-packs/_bundled/g7-core-ja/backend/ja/errors.php');

        if (! file_exists($path)) {
            $this->markTestSkipped('번들 ja 언어팩 미존재');
        }

        $ja = require $path;

        $this->assertArrayHasKey('bootstrap', $ja);
        $this->assertArrayHasKey('incompatible_title', $ja['bootstrap']);
        $this->assertArrayHasKey('incompatible_message', $ja['bootstrap']);
        $this->assertArrayHasKey('blocked_title', $ja['bootstrap']);
        $this->assertArrayHasKey('blocked_message', $ja['bootstrap']);
        $this->assertNotSame(
            $ja['bootstrap']['message'],
            $ja['bootstrap']['incompatible_message'],
            'ja 비호환 문구가 네트워크 문구와 동일하다'
        );
        $this->assertNotSame(
            $ja['bootstrap']['message'],
            $ja['bootstrap']['blocked_message'],
            'ja 차단 문구가 네트워크 문구와 동일하다'
        );
    }

    public function test_인라인_부트스트랩은_구형_브라우저_문법만_사용한다(): void
    {
        $html = $this->render();

        preg_match('/<script>(.*?)<\/script>/s', $html, $m);
        $this->assertNotEmpty($m, '인라인 부트스트랩 스크립트를 찾지 못했다');

        // 주석 안의 백틱·화살표는 문법이 아니다 — 블록 주석과 줄 주석을 먼저 걷어낸다.
        // 콜론 뒤의 `//` 는 URL 스킴이므로 주석으로 보지 않는다.
        $js = preg_replace('#/\*.*?\*/#s', '', $m[1]);
        $js = preg_replace('#(^|[^:])//[^\n]*#', '$1', $js);

        // 코어 번들이 없어도 이 계층은 살아 있어야 한다 — ES2015+ 문법 금지.
        $forbidden = [
            '화살표 함수' => '/\)\s*=>/',
            'const 선언' => '/(^|[^\w$])const\s+[A-Za-z_$]/m',
            'let 선언' => '/(^|[^\w$])let\s+[A-Za-z_$]/m',
            '템플릿 리터럴' => '/`/',
            '옵셔널 체이닝' => '/\?\./',
        ];

        foreach ($forbidden as $label => $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, $js),
                "인라인 부트스트랩에 {$label} 사용 — 구형 브라우저에서 안내 화면조차 뜨지 않는다"
            );
        }
    }
}
