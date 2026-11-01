<?php

namespace Tests\Unit\Support\Csp;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Support\Csp\CspPolicyBuilder;
use App\Support\TrustedScriptHosts;
use Tests\TestCase;

/**
 * 코어 CSP 정책 조립 (#158)
 *
 * 허용 호스트는 활성 확장 선언(`trusted_script_hosts` + 필터 훅) · 활성 템플릿 externals 의 외부 script ·
 * 운영자 추가 입력의 합이다. 정책 문자열에 그대로 이어 붙이므로 형식이 어긋난 호스트는 빠져야 하고
 * (`;` 하나로 다른 지시어가 끼어든다), 고정 지시어(worker-src·object-src·base-uri·report-uri)는 늘 같다.
 * 모드는 `.env` 탈출구(config `core.csp.mode_override`)가 저장값보다 우선한다.
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
}
