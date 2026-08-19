<?php

namespace Tests\Unit\Support;

use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Support\TrustedScriptHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TrustedScriptHosts 집계/판정 테스트 (KVE-2026-1915 신뢰 출처 허용목록)
 *
 * 확장이 manifest 로 선언한 외부 스크립트 호스트를 집계하고, URL 의 호스트가 그 목록에
 * 속하는지 판정하는 로직을 고정합니다. 런타임·저장측·audit 이 모두 이 SSoT 를 참조하므로
 * 호스트 추출/정규화가 틀리면 세 계층이 함께 어긋납니다.
 *
 * 선언 주체는 모듈·플러그인·템플릿 셋이고 집계 경로가 서로 다릅니다 — 모듈/플러그인은
 * PHP 추상 클래스의 getTrustedScriptHosts(), 템플릿은 manifest 배열 직접 읽기. 한 주체만
 * 검사하면 나머지 주체의 집계 누락이 드러나지 않습니다(템플릿 축이 그렇게 빠져 있던 것이
 * B-2b 결함이었습니다).
 *
 * 이 관심사는 시나리오 매니페스트(tests/scenarios/trusted-script-hosts.yaml)의
 * sub_flow `trusted_host_declaring_subject` 로 등록되어 있습니다. 브라우저 관찰 축(case)과
 * 교차하지 않는 별개 관심사라 축으로 올리면 어떤 조합도 관측 지점을 갖지 못합니다 —
 * 그래서 축이 아니라 sub_flow 이고, 커버는 아래 메서드들의 효과 마커가 담당합니다.
 *
 * 주의: 산문에 마커 문법을 그대로 인용하면 시나리오 마커 파서가
 * 그 문장을 마커로 읽어 실재하지 않는 조합을 만듭니다. 마커 이름은 평문으로만 언급합니다.
 *
 * 효과 요약(마커 아님 — 평문): every_extension_type_declaration_is_collected,
 * malformed_or_absent_declaration_is_ignored. 실제 마커는 아래 메서드에만 둡니다.
 */
class TrustedScriptHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        HookManager::clearFilter(TrustedScriptHosts::FILTER_HOOK);
        parent::tearDown();
    }

    // ── hostOf: URL → 호스트 추출 ──────────────────────────

    public function test_host_of_extracts_scheme_url_host(): void
    {
        $this->assertSame('cdn.ckeditor.com', TrustedScriptHosts::hostOf('https://cdn.ckeditor.com/ckeditor5/x.js'));
    }

    public function test_host_of_extracts_protocol_relative_host(): void
    {
        $this->assertSame('t1.daumcdn.net', TrustedScriptHosts::hostOf('//t1.daumcdn.net/mapjsapi/postcode.js'));
    }

    public function test_host_of_returns_null_for_same_origin_path(): void
    {
        $this->assertNull(TrustedScriptHosts::hostOf('/api/modules/x/y.js'));
    }

    public function test_host_of_lowercases(): void
    {
        $this->assertSame('cdn.ckeditor.com', TrustedScriptHosts::hostOf('https://CDN.CKEditor.COM/x.js'));
    }

    // ── isTrustedUrl: 명시 목록 대조 ───────────────────────

    public function test_is_trusted_url_allows_listed_host(): void
    {
        $this->assertTrue(
            TrustedScriptHosts::isTrustedUrl('https://cdn.ckeditor.com/x.js', ['cdn.ckeditor.com'])
        );
    }

    public function test_is_trusted_url_blocks_unlisted_host(): void
    {
        $this->assertFalse(
            TrustedScriptHosts::isTrustedUrl('https://evil.com/x.js', ['cdn.ckeditor.com'])
        );
    }

    public function test_is_trusted_url_blocks_same_origin_path(): void
    {
        // same-origin 경로는 호스트가 없으므로 신뢰 목록 판정 대상이 아니다(별도 path 규칙이 허용)
        $this->assertFalse(
            TrustedScriptHosts::isTrustedUrl('/local/x.js', ['cdn.ckeditor.com'])
        );
    }

    // ── hostOf: authority 우회 정규화 (KVE-2026-1915 3층 동형) ──
    //
    // 브라우저(WHATWG URL)는 파싱 전에 ASCII tab·LF·CR 을 제거하고 special scheme 에서
    // 백슬래시를 슬래시와 동등 처리한다. 그 정규화 없이 parse_url 로 호스트를 뽑으면
    // `https://evil.com\@cdn.ckeditor.com/x.js` 의 호스트를 `cdn.ckeditor.com`(userinfo 해석)
    // 으로 보게 되는데, 브라우저는 같은 문자열을 `evil.com` 에서 로드한다.
    // 런타임(TemplateApp)·정적검사(layout-scripts-src-same-origin)는 이미 정규화하므로
    // 이 판정만 갈리면 "저장은 되는데 로드는 안 되는" 비대칭이 생긴다.

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function authorityNormalizationProvider(): array
    {
        $bs = chr(92);  // 백슬래시
        $tab = chr(9);
        $lf = chr(10);
        $cr = chr(13);

        return [
            // 신뢰 호스트를 userinfo 로 위장 → 실제 출처는 evil.com
            'backslash userinfo (scheme)' => ['https://evil.com'.$bs.'@cdn.ckeditor.com/x.js', 'evil.com'],
            'backslash userinfo (protocol-relative)' => ['//evil.com'.$bs.'@cdn.ckeditor.com/x.js', 'evil.com'],
            'tab userinfo' => ['https://evil.com'.$tab.'@cdn.ckeditor.com/x.js', 'cdn.ckeditor.com'],
            'lf in host' => ['https://evil'.$lf.'.com/x.js', 'evil.com'],
            'cr in host' => ['https://evil'.$cr.'.com/x.js', 'evil.com'],
            // 역방향: 문자열상 path 지만 브라우저는 authority 로 해석 → 호스트가 나와야 한다
            'backslash authority from path' => ['/'.$bs.'/cdn.ckeditor.com/x.js', 'cdn.ckeditor.com'],
        ];
    }

    #[DataProvider('authorityNormalizationProvider')]
    public function test_host_of_normalizes_authority_like_browser(string $url, ?string $expected): void
    {
        $this->assertSame($expected, TrustedScriptHosts::hostOf($url));
    }

    public function test_is_trusted_url_rejects_backslash_userinfo_disguise(): void
    {
        $this->assertFalse(
            TrustedScriptHosts::isTrustedUrl(
                'https://evil.com'.chr(92).'@cdn.ckeditor.com/x.js',
                ['cdn.ckeditor.com']
            )
        );
    }

    public function test_is_trusted_url_allows_backslash_authority_to_trusted_host(): void
    {
        // 런타임은 이 형태를 `//cdn.ckeditor.com/x.js` 로 해석해 허용한다.
        // 저장측이 거부하면 신뢰 호스트 스크립트가 저장 불가가 되는 과차단 비대칭이다.
        $this->assertTrue(
            TrustedScriptHosts::isTrustedUrl(
                '/'.chr(92).'/cdn.ckeditor.com/x.js',
                ['cdn.ckeditor.com']
            )
        );
    }

    public function test_host_of_still_returns_null_for_plain_path_with_inner_backslash(): void
    {
        // 경로 중간의 백슬래시는 authority 를 만들지 않는다 (과차단 회귀 방지)
        $this->assertNull(TrustedScriptHosts::hostOf('/js/a'.chr(92).'b.js'));
    }

    // ── hosts(): 활성 모듈·플러그인 manifest 집계 ───────────
    //
    // 모듈/플러그인은 PHP 추상 클래스의 `getTrustedScriptHosts()` 로 manifest 를 읽어
    // 집계된다(템플릿과 경로가 다르다). 이 축이 없으면 추상 클래스의 manifest 파싱이
    // 깨져도 템플릿 테스트만 green 이라 드러나지 않는다.

    /**
     * 모듈 manifest 의 선언이 읽히고, 비문자열·공백 항목은 걸러진다.
     *
     * @effects every_extension_type_declaration_is_collected, malformed_or_absent_declaration_is_ignored
     */
    public function test_module_reads_declared_hosts_from_manifest(): void
    {
        $module = $this->fakeExtension(AbstractModule::class, [
            'trusted_script_hosts' => ['  cdn.module.example  ', 123, null, ''],
        ]);

        $this->assertSame(['cdn.module.example'], $module->getTrustedScriptHosts());
    }

    /**
     * 모듈 manifest 가 배열이 아니면 빈 목록으로 처리된다 (손상된 manifest 방어).
     *
     * @effects malformed_or_absent_declaration_is_ignored
     */
    public function test_module_ignores_non_array_declaration(): void
    {
        $module = $this->fakeExtension(AbstractModule::class, ['trusted_script_hosts' => 'not-an-array']);

        $this->assertSame([], $module->getTrustedScriptHosts());
    }

    /**
     * 플러그인 manifest 의 선언이 읽히고, 비문자열·공백 항목은 걸러진다.
     *
     * @effects every_extension_type_declaration_is_collected, malformed_or_absent_declaration_is_ignored
     */
    public function test_plugin_reads_declared_hosts_from_manifest(): void
    {
        $plugin = $this->fakeExtension(AbstractPlugin::class, [
            'trusted_script_hosts' => ['cdn.plugin.example', false],
        ]);

        $this->assertSame(['cdn.plugin.example'], $plugin->getTrustedScriptHosts());
    }

    /**
     * 선언이 아예 없는 manifest 는 빈 목록이다 (미선언 확장이 다수인 정상 경로).
     *
     * @effects malformed_or_absent_declaration_is_ignored
     */
    public function test_plugin_without_declaration_returns_empty(): void
    {
        $plugin = $this->fakeExtension(AbstractPlugin::class, ['name' => 'x']);

        $this->assertSame([], $plugin->getTrustedScriptHosts());
    }

    /**
     * 활성 모듈·플러그인이 선언한 호스트가 집계에 합류한다.
     *
     * @effects every_extension_type_declaration_is_collected
     */
    public function test_hosts_includes_active_module_and_plugin_declared_hosts(): void
    {
        $this->fakeActiveExtensions(
            modules: [$this->fakeExtension(AbstractModule::class, ['trusted_script_hosts' => ['cdn.module.example']])],
            plugins: [$this->fakeExtension(AbstractPlugin::class, ['trusted_script_hosts' => ['cdn.plugin.example']])]
        );

        $hosts = TrustedScriptHosts::hosts();

        $this->assertContains('cdn.module.example', $hosts);
        $this->assertContains('cdn.plugin.example', $hosts);
    }

    /**
     * 모듈 집계가 실패해도 나머지 주체의 집계는 계속된다 (fine-grained 실패 격리).
     *
     * @effects malformed_or_absent_declaration_is_ignored
     */
    public function test_hosts_survives_module_aggregation_failure(): void
    {
        $moduleManager = \Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andThrow(new \RuntimeException('boom'));
        $this->instance(ModuleManager::class, $moduleManager);

        $pluginManager = \Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([
            $this->fakeExtension(AbstractPlugin::class, ['trusted_script_hosts' => ['cdn.plugin.example']]),
        ]);
        $this->instance(PluginManager::class, $pluginManager);

        $this->assertContains('cdn.plugin.example', TrustedScriptHosts::hosts());
    }

    // ── hosts(): 집계 + 훅 확장 ────────────────────────────

    public function test_hosts_includes_hook_declared_hosts(): void
    {
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $hosts) => array_merge($hosts, ['cdn.example.com']),
            priority: 1
        );

        $this->assertContains('cdn.example.com', TrustedScriptHosts::hosts());
    }

    public function test_hosts_normalizes_and_dedupes(): void
    {
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $hosts) => array_merge($hosts, ['  CDN.Example.com  ', 'cdn.example.com']),
            priority: 1
        );

        $hosts = TrustedScriptHosts::hosts();
        $matches = array_filter($hosts, fn ($h) => $h === 'cdn.example.com');

        $this->assertCount(1, $matches, '정규화(trim/lowercase) 후 중복이 제거되어야 합니다');
    }

    // ── hosts(): 활성 템플릿 manifest 집계 ─────────────────
    //
    // 템플릿은 모듈/플러그인과 달리 PHP 추상 클래스가 없고 manifest 배열로 다뤄지므로,
    // `getActiveTemplate()` 이 돌려주는 template.json 배열에서 직접 읽는다.
    // 이 축이 빠지면 템플릿이 선언한 호스트를 정적 검사는 통과시키는데 런타임·저장측이
    // 차단해, 문서가 지원한다고 적은 기능이 화면에서 조용히 동작하지 않는다.

    /**
     * 활성 관리자 템플릿이 선언한 호스트가 집계된다.
     *
     * @effects every_extension_type_declaration_is_collected
     */
    public function test_hosts_includes_active_admin_template_declared_hosts(): void
    {
        $this->fakeActiveTemplates(
            admin: ['identifier' => 'vendor-admin_x', 'trusted_script_hosts' => ['cdn.admin-template.example']],
            user: null
        );

        $this->assertContains('cdn.admin-template.example', TrustedScriptHosts::hosts());
    }

    /**
     * 활성 사용자 템플릿이 선언한 호스트가 집계된다.
     *
     * @effects every_extension_type_declaration_is_collected
     */
    public function test_hosts_includes_active_user_template_declared_hosts(): void
    {
        $this->fakeActiveTemplates(
            admin: null,
            user: ['identifier' => 'vendor-user_x', 'trusted_script_hosts' => ['cdn.user-template.example']]
        );

        $this->assertContains('cdn.user-template.example', TrustedScriptHosts::hosts());
    }

    /**
     * 템플릿 manifest 의 비문자열/비배열 값은 무시된다 (손상된 manifest 방어).
     *
     * @effects malformed_or_absent_declaration_is_ignored
     */
    public function test_hosts_ignores_malformed_template_declarations(): void
    {
        $this->fakeActiveTemplates(
            admin: ['identifier' => 'vendor-admin_x', 'trusted_script_hosts' => 'not-an-array'],
            user: ['identifier' => 'vendor-user_x', 'trusted_script_hosts' => ['cdn.ok.example', 123, null]]
        );

        $hosts = TrustedScriptHosts::hosts();

        $this->assertContains('cdn.ok.example', $hosts);
        $this->assertNotContains('not-an-array', $hosts);
    }

    /**
     * 활성 템플릿이 없어도 집계가 실패하지 않는다.
     *
     * @effects malformed_or_absent_declaration_is_ignored
     */
    public function test_hosts_survives_absent_active_templates(): void
    {
        $this->fakeActiveTemplates(admin: null, user: null);

        $this->assertIsArray(TrustedScriptHosts::hosts());
    }

    /**
     * manifest 를 주입한 확장(모듈/플러그인) 인스턴스를 만듭니다.
     *
     * `loadManifest()` 만 대체하므로 `getTrustedScriptHosts()` 의 실제 파싱·필터 로직이
     * 그대로 실행됩니다.
     *
     * @param  class-string  $abstract  AbstractModule::class 또는 AbstractPlugin::class
     * @param  array<string, mixed>  $manifest  주입할 manifest 배열
     * @return mixed 부분 목 확장 인스턴스
     */
    private function fakeExtension(string $abstract, array $manifest): mixed
    {
        $extension = \Mockery::mock($abstract)->makePartial();
        $extension->shouldAllowMockingProtectedMethods();
        $extension->shouldReceive('loadManifest')->andReturn($manifest);

        return $extension;
    }

    /**
     * ModuleManager/PluginManager 를 대체해 활성 확장 목록을 주입합니다.
     *
     * @param  array<int, mixed>  $modules  활성 모듈 인스턴스 목록
     * @param  array<int, mixed>  $plugins  활성 플러그인 인스턴스 목록
     */
    private function fakeActiveExtensions(array $modules, array $plugins): void
    {
        $moduleManager = \Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn($modules);
        $this->instance(ModuleManager::class, $moduleManager);

        $pluginManager = \Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn($plugins);
        $this->instance(PluginManager::class, $pluginManager);
    }

    /**
     * TemplateManager 를 대체해 활성 템플릿 manifest 를 주입합니다.
     *
     * @param  array<string, mixed>|null  $admin  활성 관리자 템플릿 manifest
     * @param  array<string, mixed>|null  $user  활성 사용자 템플릿 manifest
     */
    private function fakeActiveTemplates(?array $admin, ?array $user): void
    {
        $manager = \Mockery::mock(TemplateManager::class);
        $manager->shouldReceive('getActiveTemplate')->with('admin')->andReturn($admin);
        $manager->shouldReceive('getActiveTemplate')->with('user')->andReturn($user);

        $this->instance(TemplateManager::class, $manager);
    }
}
