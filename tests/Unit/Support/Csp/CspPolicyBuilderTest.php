<?php

namespace Tests\Unit\Support\Csp;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Support\Csp\CspPolicyBuilder;
use App\Support\TrustedScriptHosts;
use App\Support\TrustedStyleHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 코어 CSP 정책 조립 (공개 #158)
 *
 * 허용 호스트는 활성 확장 선언(`trusted_script_hosts` + 필터 훅) · 활성 템플릿 externals 의 외부 script ·
 * 운영자 추가 입력의 합이다. 정책 문자열에 그대로 이어 붙이므로 형식이 어긋난 호스트는 빠져야 하고
 * (`;` 하나로 다른 지시어가 끼어든다), 고정 지시어(worker-src·object-src·base-uri·report-uri)는 늘 같다.
 * 모드는 `.env` 탈출구(config `core.csp.mode_override`)가 저장값보다 우선한다.
 *
 * 스타일 정책(7.1.6)은 강도 2단계(basic = `style-src-elem`, strict = `style-src` + `style-src-attr 'none'`)이고
 * 호스트는 활성 템플릿 externals 의 외부 스타일시트 + 켜진 확장 선언(`trusted_style_hosts` + 필터 훅) + 운영자 입력이다. 모드와 탈출구(`core.csp.style_mode_override`)는
 * 스크립트와 따로 정하며, 두 모드의 조합이 나가는 헤더 수(0·1·2)를 정한다. `build()` 문자열은 그대로다.
 *
 * 확장 관리자는 모의 객체로 바꿔 집계 입력을 고정한다 — 실제 활성 확장은 설치 상태마다 달라진다.
 */
class CspPolicyBuilderTest extends TestCase
{
    /**
     * 모의 관리자를 심고 저장값을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $modules = \Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([]);
        $this->instance(ModuleManager::class, $modules);

        $plugins = \Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([]);
        $this->instance(PluginManager::class, $plugins);

        $this->fakeTemplates([]);

        config([
            'core.csp.mode_override' => null,
            'g7_settings.core.security.csp_mode' => 'off',
            'g7_settings.core.security.csp_extra_script_hosts' => [],
            'core.csp.style_mode_override' => null,
            'g7_settings.core.security.csp_style_mode' => 'off',
            'g7_settings.core.security.csp_style_level' => 'basic',
            'g7_settings.core.security.csp_extra_style_hosts' => [],
        ]);
    }

    /**
     * 훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(TrustedScriptHosts::FILTER_HOOK);
        HookManager::clearFilter(TrustedStyleHosts::FILTER_HOOK);

        parent::tearDown();
    }

    /**
     * 활성 템플릿(admin/user)을 모의합니다.
     *
     * @param  array<string, array<string, mixed>>  $byType  타입 => 템플릿 manifest
     * @return void 반환값 없음
     */
    private function fakeTemplates(array $byType): void
    {
        $templates = \Mockery::mock(TemplateManager::class);
        $templates->shouldReceive('getActiveTemplate')->andReturnUsing(fn (string $type) => $byType[$type] ?? null);
        $this->instance(TemplateManager::class, $templates);
    }

    /**
     * 신뢰 호스트 훅에 호스트를 더합니다.
     *
     * @param  array<int, string>  $hosts  더할 호스트
     * @return void 반환값 없음
     */
    private function declareHosts(array $hosts): void
    {
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $current) => array_merge($current, $hosts),
            priority: 1
        );
    }

    /**
     * 선언 호스트 · externals 외부 script · 추가 호스트를 합치고 소문자·중복 제거·정렬한다.
     *
     * @scenario case=source_extension_declared
     *
     * @effects csp_hosts_merged_deduped_sorted
     *
     * @return void 반환값 없음
     */
    public function test_합산_중복_제거_정렬(): void
    {
        $this->declareHosts(['t1.daumcdn.net', 'JS.TossPayments.com']);
        $this->fakeTemplates([
            'user' => [
                'identifier' => 'sirsoft-basic',
                'externals' => [
                    ['id' => 'sdk', 'type' => 'script', 'url' => 'https://sdk.example.com/a.js'],
                    ['id' => 'own', 'type' => 'script', 'asset' => 'vendor/own.js'],
                    ['id' => 'css', 'type' => 'style', 'url' => 'https://css.example.com/a.css'],
                ],
            ],
        ]);
        config(['g7_settings.core.security.csp_extra_script_hosts' => ['cdn.example.com', 't1.daumcdn.net']]);

        $builder = new CspPolicyBuilder;

        $this->assertSame(['js.tosspayments.com', 'sdk.example.com', 't1.daumcdn.net'], $builder->autoHosts());
        $this->assertStringStartsWith(
            "script-src 'self' cdn.example.com js.tosspayments.com sdk.example.com t1.daumcdn.net;",
            $builder->build()
        );
        // 같은 출처 asset·style 항목은 script-src 에 오지 않는다
        $this->assertStringNotContainsString('css.example.com', $builder->build());
    }

    /**
     * 설치됐지만 꺼진 모듈·플러그인의 선언 호스트는 정책에 들어가지 않는다.
     *
     * 확장 관리자는 활성 목록과 전체 목록을 둘 다 내주게 두고, 꺼진 확장 호스트만 빠지는지 본다 —
     * 집계가 전체 목록을 읽게 바뀌면 꺼진 결제 플러그인의 SDK 호스트가 허용 목록에 남는다.
     *
     * @scenario case=source_extension_declared
     *
     * @effects csp_inactive_extension_hosts_excluded
     *
     * @return void 반환값 없음
     */
    public function test_꺼진_확장의_선언_호스트는_빠진다(): void
    {
        $extension = fn (array $hosts) => new class($hosts)
        {
            /**
             * 선언 호스트를 받아 둡니다.
             *
             * @param  array<int, string>  $hosts  선언 호스트
             */
            public function __construct(private array $hosts) {}

            /**
             * manifest 선언 호스트를 돌려줍니다.
             *
             * @return array<int, string> 선언 호스트
             */
            public function getTrustedScriptHosts(): array
            {
                return $this->hosts;
            }
        };
        $activeModule = $extension(['active-module.example.com']);
        $inactiveModule = $extension(['inactive-module.example.com']);
        $activePlugin = $extension(['active-plugin.example.com']);
        $inactivePlugin = $extension(['inactive-plugin.example.com']);

        $modules = \Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([$activeModule]);
        $modules->shouldReceive('getAllModules')->andReturn([$activeModule, $inactiveModule]);
        $this->instance(ModuleManager::class, $modules);

        $plugins = \Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([$activePlugin]);
        $plugins->shouldReceive('getAllPlugins')->andReturn([$activePlugin, $inactivePlugin]);
        $this->instance(PluginManager::class, $plugins);

        $builder = new CspPolicyBuilder;

        $this->assertSame(['active-module.example.com', 'active-plugin.example.com'], $builder->autoHosts());
        $this->assertStringNotContainsString('inactive-', $builder->build());
    }

    /**
     * 고정 지시어 문자열.
     *
     * @scenario case=source_none
     *
     * @effects csp_policy_fixed_directives
     *
     * @return void 반환값 없음
     */
    public function test_고정_지시어_문자열(): void
    {
        $this->assertSame(
            "script-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report",
            (new CspPolicyBuilder)->build()
        );
    }

    /**
     * 형식이 어긋난 선언 호스트(세미콜론·따옴표·키워드)는 정책에서 빠진다.
     *
     * @scenario case=source_extension_declared
     *
     * @effects csp_invalid_hosts_dropped
     *
     * @return void 반환값 없음
     */
    public function test_형식이_어긋난_호스트는_빠진다(): void
    {
        $this->declareHosts(['ok.example.com', "evil.com; script-src 'unsafe-inline'", "'unsafe-eval'", '*', 'http://x.example.com']);

        $policy = (new CspPolicyBuilder)->build();

        $this->assertStringContainsString('ok.example.com', $policy);
        $this->assertStringNotContainsString('unsafe', $policy);
        $this->assertStringNotContainsString('evil.com', $policy);
        $this->assertStringNotContainsString('http://', $policy);
        $this->assertSame(5, substr_count($policy, ';') + 1, '지시어 수가 늘었다 — 호스트로 지시어가 끼어들었다');
    }

    /**
     * 모드: 저장값 → 헤더 이름, 잘못된 저장값은 끔, `.env` 탈출구가 우선한다.
     *
     * @scenario case=source_none
     *
     * @effects csp_mode_override_precedence
     *
     * @return void 반환값 없음
     */
    public function test_모드와_탈출구_우선순위(): void
    {
        config(['g7_settings.core.security.csp_mode' => 'enforce']);
        $this->assertSame('enforce', (new CspPolicyBuilder)->mode());
        $this->assertSame('Content-Security-Policy', CspPolicyBuilder::headerName('enforce'));
        $this->assertSame('Content-Security-Policy-Report-Only', CspPolicyBuilder::headerName('report_only'));
        $this->assertNull(CspPolicyBuilder::headerName('off'));

        config(['core.csp.mode_override' => ' OFF ']);
        $this->assertSame('off', (new CspPolicyBuilder)->mode());
        $this->assertSame('off', (new CspPolicyBuilder)->modeOverride());

        config(['core.csp.mode_override' => 'bogus', 'g7_settings.core.security.csp_mode' => 'nonsense']);
        $this->assertNull((new CspPolicyBuilder)->modeOverride());
        $this->assertSame('off', (new CspPolicyBuilder)->mode());
    }

    /**
     * 스타일 정책 강도별 지시어 — 기본은 `style-src-elem` 하나, 엄격은 `style-src` + `style-src-attr 'none'`.
     *
     * 기본을 `style-src …; style-src-attr 'unsafe-inline'` 로 쓰면 구형 브라우저에서 엄격으로 동작하므로
     * 문자열을 통째로 잠근다. `data:`·`blob:` 는 싣지 않는다.
     *
     * @scenario case=style_level_basic
     *
     * @effects csp_style_directives_follow_level
     *
     * @return void 반환값 없음
     */
    public function test_스타일_강도별_지시어(): void
    {
        config(['g7_settings.core.security.csp_extra_style_hosts' => ['fonts.example.com']]);

        $basic = new CspPolicyBuilder;
        $this->assertSame('basic', $basic->styleLevel());
        $this->assertSame(["style-src-elem 'self' fonts.example.com"], $basic->styleDirectives());
        $this->assertSame("style-src-elem 'self' fonts.example.com; report-uri /api/csp-report", $basic->buildStyle());

        config(['g7_settings.core.security.csp_style_level' => 'strict']);

        $strict = new CspPolicyBuilder;
        $this->assertSame('strict', $strict->styleLevel());
        $this->assertSame(["style-src 'self' fonts.example.com", "style-src-attr 'none'"], $strict->styleDirectives());
        $this->assertSame(
            "style-src 'self' fonts.example.com; style-src-attr 'none'; report-uri /api/csp-report",
            $strict->buildStyle()
        );

        foreach ([$basic->buildStyle(), $strict->buildStyle()] as $policy) {
            $this->assertStringNotContainsString('unsafe-inline', $policy);
            $this->assertStringNotContainsString('data:', $policy);
            $this->assertStringNotContainsString('blob:', $policy);
        }
    }

    /**
     * 호스트가 없을 때의 스타일 정책 문자열.
     *
     * @scenario case=style_level_strict
     *
     * @effects csp_style_directives_follow_level
     *
     * @return void 반환값 없음
     */
    public function test_스타일_호스트가_없으면_self_만(): void
    {
        $this->assertSame("style-src-elem 'self'; report-uri /api/csp-report", (new CspPolicyBuilder)->buildStyle());

        config(['g7_settings.core.security.csp_style_level' => 'strict']);
        $this->assertSame(
            "style-src 'self'; style-src-attr 'none'; report-uri /api/csp-report",
            (new CspPolicyBuilder)->buildStyle()
        );
    }

    /**
     * 스타일 호스트 = 활성 템플릿 externals 의 외부 스타일시트(style·webfont·preload as=style) + 운영자 입력.
     *
     * script·같은 출처 asset·글꼴 preload 는 스타일 호스트가 아니고, 확장의 `trusted_script_hosts` 선언도
     * 스타일 정책에 오지 않는다. 반대로 스타일 호스트는 `script-src` 에 오지 않는다.
     *
     * @scenario case=style_source_template_externals
     *
     * @effects csp_style_hosts_merged_deduped_sorted
     *
     * @return void 반환값 없음
     */
    public function test_스타일_호스트_합산(): void
    {
        $this->declareHosts(['js.tosspayments.com']);
        $this->fakeTemplates([
            'admin' => [
                'identifier' => 'sirsoft-admin_basic',
                'externals' => [
                    ['id' => 'font', 'type' => 'webfont', 'url' => 'https://Fonts.Example.com/a.css'],
                    ['id' => 'pre', 'type' => 'preload', 'as' => 'style', 'url' => 'https://preload.example.com/a.css'],
                    ['id' => 'prefont', 'type' => 'preload', 'as' => 'font', 'url' => 'https://woff.example.com/a.woff2'],
                ],
            ],
            'user' => [
                'identifier' => 'sirsoft-basic',
                'externals' => [
                    ['id' => 'css', 'type' => 'style', 'url' => 'https://css.example.com:8443/a.css'],
                    ['id' => 'sdk', 'type' => 'script', 'url' => 'https://sdk.example.com/a.js'],
                    ['id' => 'own', 'type' => 'style', 'asset' => 'vendor/own.css'],
                ],
            ],
        ]);
        config(['g7_settings.core.security.csp_extra_style_hosts' => ['  CDN.Example.com ', 'css.example.com:8443']]);

        $builder = new CspPolicyBuilder;

        $this->assertSame(['css.example.com:8443', 'fonts.example.com', 'preload.example.com'], $builder->styleAutoHosts());
        $this->assertSame(['cdn.example.com', 'css.example.com:8443'], $builder->styleExtraHosts());
        $this->assertSame(
            "style-src-elem 'self' cdn.example.com css.example.com:8443 fonts.example.com preload.example.com; report-uri /api/csp-report",
            $builder->buildStyle()
        );

        foreach (['sdk.example.com', 'woff.example.com', 'js.tosspayments.com', 'vendor/own.css'] as $absent) {
            $this->assertStringNotContainsString($absent, $builder->buildStyle());
        }

        // 스타일 호스트는 스크립트 정책에 섞이지 않는다
        $this->assertSame(['js.tosspayments.com', 'sdk.example.com'], $builder->autoHosts());
        $this->assertStringNotContainsString('fonts.example.com', $builder->build());
        $this->assertStringNotContainsString('cdn.example.com', $builder->build());
    }

    /**
     * 켜진 모듈·플러그인이 manifest `trusted_style_hosts` 로 선언한 호스트는 스타일 정책에 자동으로 들어간다.
     *
     * 결제사 결제창처럼 외부 스크립트가 자기 호스트의 CSS 파일을 싣는 확장이 있다. 선언할 길이 없으면 스타일 정책을
     * 강제한 사이트에서 결제창이 화면을 덮지 못한다(운영자가 추가 호스트를 직접 넣어야 했다). 꺼진 확장의 선언은
     * 빠지고, 스타일 선언은 `script-src` 에 섞이지 않으며, 필터 훅으로도 더할 수 있다.
     *
     * @scenario case=style_source_extension_declared
     *
     * @effects csp_style_extension_declared_hosts_included, csp_style_inactive_extension_hosts_excluded
     *
     * @return void 반환값 없음
     */
    public function test_확장이_선언한_스타일_호스트는_자동으로_합산된다(): void
    {
        $extension = fn (array $styleHosts, array $scriptHosts = []) => new class($styleHosts, $scriptHosts)
        {
            /**
             * 선언 호스트를 받아 둡니다.
             *
             * @param  array<int, string>  $styleHosts  스타일 선언 호스트
             * @param  array<int, string>  $scriptHosts  스크립트 선언 호스트
             */
            public function __construct(private array $styleHosts, private array $scriptHosts) {}

            /**
             * manifest 스타일 선언 호스트를 돌려줍니다.
             *
             * @return array<int, string> 선언 호스트
             */
            public function getTrustedStyleHosts(): array
            {
                return $this->styleHosts;
            }

            /**
             * manifest 스크립트 선언 호스트를 돌려줍니다.
             *
             * @return array<int, string> 선언 호스트
             */
            public function getTrustedScriptHosts(): array
            {
                return $this->scriptHosts;
            }
        };
        $activePlugin = $extension(['StdPay.Example.com', 'stdpay.example.com'], ['sdk.example.com']);
        $inactivePlugin = $extension(['inactive-style.example.com']);
        $activeModule = $extension(['module-css.example.com', "evil.example.com; style-src-attr 'unsafe-inline'"]);

        $modules = \Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([$activeModule]);
        $modules->shouldReceive('getAllModules')->andReturn([$activeModule]);
        $this->instance(ModuleManager::class, $modules);

        $plugins = \Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([$activePlugin]);
        $plugins->shouldReceive('getAllPlugins')->andReturn([$activePlugin, $inactivePlugin]);
        $this->instance(PluginManager::class, $plugins);

        HookManager::addFilter(
            TrustedStyleHosts::FILTER_HOOK,
            fn (array $current) => array_merge($current, ['hook-css.example.com']),
            priority: 1
        );

        $builder = new CspPolicyBuilder;

        $this->assertSame(['hook-css.example.com', 'module-css.example.com', 'stdpay.example.com'], $builder->styleAutoHosts());
        $this->assertSame(
            "style-src-elem 'self' hook-css.example.com module-css.example.com stdpay.example.com; report-uri /api/csp-report",
            $builder->buildStyle()
        );
        $this->assertStringNotContainsString('inactive-style', $builder->buildStyle());
        $this->assertStringNotContainsString('unsafe-inline', $builder->buildStyle());

        // 스타일 선언은 스크립트 정책에, 스크립트 선언은 스타일 정책에 섞이지 않는다
        $this->assertSame(['sdk.example.com'], $builder->autoHosts());
        $this->assertStringNotContainsString('stdpay.example.com', $builder->build());
        $this->assertStringNotContainsString('sdk.example.com', $builder->buildStyle());
    }

    /**
     * 형식이 어긋난 스타일 호스트(세미콜론·따옴표·키워드·`*`·`http://`)는 정책에서 빠진다.
     *
     * @scenario case=style_level_strict
     *
     * @effects csp_style_invalid_hosts_dropped
     *
     * @return void 반환값 없음
     */
    public function test_형식이_어긋난_스타일_호스트는_빠진다(): void
    {
        config([
            'g7_settings.core.security.csp_style_level' => 'strict',
            'g7_settings.core.security.csp_extra_style_hosts' => [
                'ok.example.com', "evil.com; style-src-attr 'unsafe-inline'", "'unsafe-inline'", '*', 'http://x.example.com', 'data:', ['x'],
            ],
        ]);

        $policy = (new CspPolicyBuilder)->buildStyle();

        $this->assertSame("style-src 'self' ok.example.com; style-src-attr 'none'; report-uri /api/csp-report", $policy);
    }

    /**
     * 저장된 강도가 잘못됐으면 기본(basic)으로 읽는다.
     *
     * @scenario case=style_level_basic
     *
     * @effects csp_style_level_fallback_basic
     *
     * @return void 반환값 없음
     */
    public function test_잘못된_강도는_기본(): void
    {
        foreach (['bogus', '', null, 5, ['strict']] as $stored) {
            config(['g7_settings.core.security.csp_style_level' => $stored]);

            $builder = new CspPolicyBuilder;
            $this->assertSame(CspPolicyBuilder::LEVEL_BASIC, $builder->styleLevel());
            $this->assertStringStartsWith('style-src-elem ', $builder->buildStyle());
        }

        $this->assertSame(['basic', 'strict'], CspPolicyBuilder::LEVELS);
    }

    /**
     * 스타일 모드: 저장값 → 잘못된 값은 끔 → `.env G7_CSP_STYLE_MODE` 탈출구 우선.
     * 스크립트 탈출구(`G7_CSP_MODE`)와 서로 영향을 주지 않는다.
     *
     * @scenario case=style_mode_override
     *
     * @effects csp_style_mode_override_independent
     *
     * @return void 반환값 없음
     */
    public function test_스타일_탈출구_우선순위와_독립성(): void
    {
        config(['g7_settings.core.security.csp_style_mode' => 'enforce']);
        $this->assertSame('enforce', (new CspPolicyBuilder)->styleMode());
        $this->assertNull((new CspPolicyBuilder)->styleModeOverride());

        config(['core.csp.style_mode_override' => ' Report_Only ']);
        $this->assertSame('report_only', (new CspPolicyBuilder)->styleModeOverride());
        $this->assertSame('report_only', (new CspPolicyBuilder)->styleMode());

        config(['core.csp.style_mode_override' => 'bogus', 'g7_settings.core.security.csp_style_mode' => 'nonsense']);
        $this->assertNull((new CspPolicyBuilder)->styleModeOverride());
        $this->assertSame('off', (new CspPolicyBuilder)->styleMode());

        // 스크립트 탈출구 off 는 스타일을 끄지 않는다
        config([
            'core.csp.mode_override' => 'off',
            'core.csp.style_mode_override' => null,
            'g7_settings.core.security.csp_mode' => 'enforce',
            'g7_settings.core.security.csp_style_mode' => 'enforce',
        ]);
        $builder = new CspPolicyBuilder;
        $this->assertSame('off', $builder->mode());
        $this->assertSame('enforce', $builder->styleMode());
        $this->assertSame(
            ['Content-Security-Policy' => "style-src-elem 'self'; report-uri /api/csp-report"],
            $builder->headers()
        );

        // 스타일 탈출구 off 는 스크립트를 끄지 않는다
        config(['core.csp.mode_override' => null, 'core.csp.style_mode_override' => 'off']);
        $builder = new CspPolicyBuilder;
        $this->assertSame('enforce', $builder->mode());
        $this->assertSame('off', $builder->styleMode());
        $this->assertSame(['Content-Security-Policy' => $builder->build()], $builder->headers());
    }

    /**
     * 헤더 조합 3×3 케이스.
     *
     * 값의 `script`·`style`·`both` 는 그 헤더에 실릴 지시어 묶음을 뜻한다.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}> 스크립트 모드, 스타일 모드, 헤더 이름 => 묶음
     */
    public static function headerCombinations(): array
    {
        $enforce = 'Content-Security-Policy';
        $report = 'Content-Security-Policy-Report-Only';

        return [
            'off × off' => ['off', 'off', []],
            'off × report_only' => ['off', 'report_only', [$report => 'style']],
            'off × enforce' => ['off', 'enforce', [$enforce => 'style']],
            'report_only × off' => ['report_only', 'off', [$report => 'script']],
            'report_only × report_only' => ['report_only', 'report_only', [$report => 'both']],
            'report_only × enforce' => ['report_only', 'enforce', [$report => 'script', $enforce => 'style']],
            'enforce × off' => ['enforce', 'off', [$enforce => 'script']],
            'enforce × report_only' => ['enforce', 'report_only', [$enforce => 'script', $report => 'style']],
            'enforce × enforce' => ['enforce', 'enforce', [$enforce => 'both']],
        ];
    }

    /**
     * 헤더 조합 — 같은 모드면 한 헤더(스크립트 → 스타일 → report-uri), 다르면 헤더 두 개, 헤더마다 report-uri 는 맨 끝 한 번.
     *
     * @scenario case=style_header_combination
     *
     * @effects csp_headers_combined_by_mode
     *
     * @param  string  $scriptMode  스크립트 모드
     * @param  string  $styleMode  스타일 모드
     * @param  array<string, string>  $expected  헤더 이름 => 묶음(script|style|both)
     * @return void 반환값 없음
     */
    #[DataProvider('headerCombinations')]
    public function test_헤더_조합(string $scriptMode, string $styleMode, array $expected): void
    {
        config([
            'g7_settings.core.security.csp_mode' => $scriptMode,
            'g7_settings.core.security.csp_style_mode' => $styleMode,
            'g7_settings.core.security.csp_style_level' => 'strict',
        ]);

        $script = "script-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'";
        $style = "style-src 'self'; style-src-attr 'none'";
        $reportUri = 'report-uri /api/csp-report';
        $values = [
            'script' => $script.'; '.$reportUri,
            'style' => $style.'; '.$reportUri,
            'both' => $script.'; '.$style.'; '.$reportUri,
        ];

        $headers = (new CspPolicyBuilder)->headers();

        $this->assertSame(array_map(fn (string $bundle) => $values[$bundle], $expected), $headers);

        foreach ($headers as $value) {
            $this->assertSame(1, substr_count($value, 'report-uri'));
            $this->assertStringEndsWith($reportUri, $value);
        }
    }

    /**
     * 스타일 정책을 켜도 `build()`(스크립트 정책·미리보기 계약)와 지시어 목록은 그대로다.
     *
     * @scenario case=style_level_strict
     *
     * @effects csp_script_policy_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_스타일을_켜도_스크립트_정책_문자열은_불변(): void
    {
        config([
            'g7_settings.core.security.csp_mode' => 'enforce',
            'g7_settings.core.security.csp_extra_script_hosts' => ['cdn.example.com'],
            'g7_settings.core.security.csp_style_mode' => 'enforce',
            'g7_settings.core.security.csp_style_level' => 'strict',
            'g7_settings.core.security.csp_extra_style_hosts' => ['fonts.example.com'],
        ]);

        $builder = new CspPolicyBuilder;
        $expected = "script-src 'self' cdn.example.com; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report";

        $this->assertSame($expected, $builder->build());
        $this->assertSame(
            ["script-src 'self' cdn.example.com", "worker-src 'self' blob:", "object-src 'none'", "base-uri 'self'"],
            $builder->scriptDirectives()
        );
        $this->assertSame(
            [
                'Content-Security-Policy' => "script-src 'self' cdn.example.com; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; "
                    ."style-src 'self' fonts.example.com; style-src-attr 'none'; report-uri /api/csp-report",
            ],
            $builder->headers()
        );
        // headers() 를 부른 뒤에도 build() 는 같은 문자열
        $this->assertSame($expected, $builder->build());
    }
}
