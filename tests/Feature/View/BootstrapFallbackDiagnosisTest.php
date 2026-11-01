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
 * 부트스트랩 셸(`public/assets/core/js/g7-shell-boot.js`·`g7-shell-head.js`)은 코어 번들이
 * 없어도 동작해야 하므로 ES5 로만 작성한다 — 화살표 함수·const·템플릿 리터럴·옵셔널 체이닝이
 * 섞이면 이 계층까지 구형 브라우저에서 죽어 안내조차 뜨지 않는다.
 *
 * 종전 인라인 `<script>` 였던 부트스트랩은 strict CSP 대응으로 정적 파일로 옮겼다(#158).
 * 분기 로직 검사는 그 파일을, 서버가 넘기는 값(문구·URL)은 파샬의 JSON 데이터 블록을 본다.
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

    /**
     * 부트스트랩 셸 JS 소스를 읽습니다.
     *
     * @param  string  $name  'boot' 또는 'head'
     * @return string 파일 내용
     */
    private function shellSource(string $name = 'boot'): string
    {
        $path = public_path("assets/core/js/g7-shell-{$name}.js");
        $this->assertFileExists($path, "셸 JS g7-shell-{$name}.js 가 없다");

        return (string) file_get_contents($path);
    }

    /**
     * 파샬이 내는 `#g7-boot-data` JSON 블록을 파싱합니다.
     *
     * @param  string  $html  렌더된 HTML
     * @return array<string, mixed> 파싱 결과
     */
    private function bootData(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '#<script type="application/json" id="g7-boot-data">(.*?)</script>#s',
            $html,
            '부트스트랩 데이터 블록이 없다'
        );
        preg_match('#<script type="application/json" id="g7-boot-data">(.*?)</script>#s', $html, $m);

        $data = json_decode($m[1], true);
        $this->assertIsArray($data, '부트스트랩 데이터 블록이 JSON 으로 파싱되지 않는다');

        return $data;
    }

    /**
     * 두 진입점의 데이터 블록 라벨과 셸의 사유별 분기 방출.
     *
     * @scenario entrypoint=user, failure_mode=parse_error
     *
     * @effects bootstrap_partial_emits_reason_branch
     *
     * @return void 반환값 없음
     */
    public function test_두_진입점_모두_사유별_분기를_렌더한다(): void
    {
        $html = $this->shellSource();

        foreach (['user', 'admin'] as $templateType) {
            $data = $this->bootData($this->render($templateType));
            $this->assertSame($templateType === 'admin' ? '[Admin]' : '[User]', $data['label']);

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
     *
     * @return void 반환값 없음
     */
    public function test_혼합_콘텐츠_차단을_별도_사유로_가른다(): void
    {
        $html = $this->shellSource();

        foreach (['user', 'admin'] as $templateType) {

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
     *
     * @return void 반환값 없음
     */
    public function test_차단_안내는_화면과_콘솔로_대상을_나눈다(): void
    {
        $html = $this->shellSource();

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

    /**
     * 셸이 window error 로 번들 SyntaxError 를 관측한다.
     *
     * @scenario surface=user_shell, csp=none, failure=none
     *
     * @effects shell_observes_bundle_syntax_error
     *
     * @return void 반환값 없음
     */
    public function test_파싱_오류_관측_리스너가_설치된다(): void
    {
        $html = $this->shellSource();

        // 파싱 실패는 <script> 의 error 가 아니라 load 를 발생시키므로 element
        // 이벤트로는 구분할 수 없다. window 의 error 이벤트로만 관측된다.
        $this->assertStringContainsString('bundleSrcs', $html);
        $this->assertStringContainsString('SyntaxError', $html);
        $this->assertStringContainsString('syntaxError', $html);
    }

    /**
     * 비호환 문구가 로케일마다 있고 데이터 블록으로 7키가 넘어간다.
     *
     * @scenario entrypoint=user, failure_mode=parse_error
     *
     * @effects incompatible_strings_defined_per_locale
     *
     * @return void 반환값 없음
     */
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

                // 셸이 화면에 그리는 문구는 데이터 블록으로 넘어간다 — 7키 전부, 현재 로케일 값으로.
                $i18n = $this->bootData($this->render())['i18n'] ?? [];
                foreach (['title', 'message', 'reload', 'incompatible_title', 'incompatible_message', 'blocked_title', 'blocked_message'] as $key) {
                    $this->assertSame(__("errors.bootstrap.{$key}"), $i18n[$key] ?? null, "[{$locale}] 데이터 블록에 errors.bootstrap.{$key} 가 없다");
                }
            }
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * 번들 ja 언어팩에도 비호환·차단 문구가 있다.
     *
     * @scenario entrypoint=user, failure_mode=parse_error
     *
     * @effects incompatible_strings_defined_per_locale
     *
     * @return void 반환값 없음
     */
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

    /**
     * 셸 JS 두 개는 ES5 문법만 쓴다 (엔진 번들을 못 읽는 브라우저에서도 안내가 떠야 한다).
     *
     * @scenario surface=user_shell, csp=none, failure=none
     *
     * @effects shell_js_is_es5_only
     *
     * @return void 반환값 없음
     */
    public function test_인라인_부트스트랩은_구형_브라우저_문법만_사용한다(): void
    {
        foreach (['head', 'boot'] as $name) {
            // 주석 안의 백틱·화살표는 문법이 아니다 — 블록 주석과 줄 주석을 먼저 걷어낸다.
            // 콜론 뒤의 `//` 는 URL 스킴이므로 주석으로 보지 않는다.
            $js = preg_replace('#/\*.*?\*/#s', '', $this->shellSource($name));
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
                    "g7-shell-{$name}.js 에 {$label} 사용 — 구형 브라우저에서 안내 화면조차 뜨지 않는다"
                );
            }
        }
    }

    /**
     * 부트스트랩 파샬은 실행형 인라인 코드를 내지 않는다 (#158).
     *
     * strict CSP(`script-src 'self'`)에서 인라인 `<script>` 와 on* 속성은 실행되지 않는다.
     * 엔진 초기화 신호가 그것에 묶이면 전 화면이 백지가 되고 폴백 안내조차 뜨지 않는다.
     *
     * @scenario surface=admin_shell, csp=webserver_strict, failure=none
     *
     * @effects bootstrap_partial_emits_no_inline_executable
     *
     * @return void 반환값 없음
     */
    public function test_부트스트랩_파샬은_인라인_실행_코드를_내지_않는다(): void
    {
        foreach (['user', 'admin'] as $templateType) {
            $html = $this->render($templateType);

            $this->assertSame(0, preg_match('/\son[a-z]+\s*=/i', $html), "[{$templateType}] on* 인라인 핸들러가 남아 있다");

            preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $scripts, PREG_SET_ORDER);
            foreach ($scripts as $script) {
                if (str_contains($script[1], 'type="application/json"')) {
                    continue;
                }
                $this->assertSame('', trim($script[2]), "[{$templateType}] 실행형 인라인 <script> 본문이 남아 있다");
            }

            // 번들 태그는 표식으로 셸 캡처 리스너에 연결된다 — 셸이 번들 태그보다 먼저 온다.
            $shell = strpos($html, 'g7-shell-boot.js');
            $core = strpos($html, 'data-g7-boot-bundle="core"');
            $components = strpos($html, 'data-g7-boot-bundle="components"');
            $this->assertNotFalse($shell);
            $this->assertNotFalse($core);
            $this->assertNotFalse($components);
            $this->assertLessThan($core, $shell, "[{$templateType}] 셸이 코어 번들보다 뒤에 있다 — load 를 놓친다");
            $this->assertLessThan($components, $core, "[{$templateType}] 코어 → 컴포넌트 순서가 깨졌다");
        }
    }
}
