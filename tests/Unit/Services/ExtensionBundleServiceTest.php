<?php

namespace Tests\Unit\Services;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Services\ExtensionBundleService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * ExtensionBundleService 단위 테스트
 *
 * 활성 확장 IIFE/CSS 의 priority 정렬, `\n;\n` 구분자 병합, sourceMappingURL
 * 처리, 확장별 fault tolerance, 캐시 파일 생성/정리를 검증한다.
 */
class ExtensionBundleServiceTest extends TestCase
{
    private string $fixtureDir;

    private ModuleManager $moduleManager;

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = storage_path('framework/testing/ext-bundle-fixtures');
        File::ensureDirectoryExists($this->fixtureDir);

        $this->moduleManager = Mockery::mock(ModuleManager::class);
        $this->pluginManager = Mockery::mock(PluginManager::class);

        // 번들 캐시 디렉토리 초기화 (테스트 격리)
        $bundleDir = storage_path('app/ext-bundles');
        if (is_dir($bundleDir)) {
            foreach (glob($bundleDir.'/*.{js,css}', GLOB_BRACE) as $f) {
                @unlink($f);
            }
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureDir)) {
            foreach (glob($this->fixtureDir.'/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->fixtureDir);
        }

        $bundleDir = storage_path('app/ext-bundles');
        if (is_dir($bundleDir)) {
            foreach (glob($bundleDir.'/*.{js,css}', GLOB_BRACE) as $f) {
                @unlink($f);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 지정 내용으로 fixture 에셋 파일을 만들고 절대 경로를 반환한다.
     */
    private function writeFixture(string $name, string $content): string
    {
        $path = $this->fixtureDir.'/'.$name;
        File::put($path, $content);

        return $path;
    }

    /**
     * hasAssets/getAssetLoadingConfig/getBuiltAssetAbsolutePaths/getIdentifier 를
     * 노출하는 가짜 확장 인스턴스를 만든다.
     */
    private function fakeExtension(string $identifier, int $priority, ?string $jsPath, ?string $cssPath, string $strategy = 'global'): object
    {
        $ext = Mockery::mock();
        $ext->shouldReceive('hasAssets')->andReturn(true);
        $ext->shouldReceive('getIdentifier')->andReturn($identifier);
        $ext->shouldReceive('getAssetLoadingConfig')->andReturn([
            'strategy' => $strategy,
            'priority' => $priority,
            'dependencies' => [],
        ]);

        $paths = [];
        if ($jsPath !== null) {
            $paths['js'] = $jsPath;
        }
        if ($cssPath !== null) {
            $paths['css'] = $cssPath;
        }
        $ext->shouldReceive('getBuiltAssetAbsolutePaths')->andReturn($paths);

        return $ext;
    }

    /**
     * 에셋 **매니페스트 선언**(`getAssets()`)을 노출하는 가짜 확장 인스턴스를 만든다.
     *
     * 산출물 경로(`getBuiltAssetAbsolutePaths`)는 존재하지 않는 파일을 가리킨다 —
     * "선언은 있는데 산출물이 없다"(dist 소실) 상태를 그대로 재현하기 위해서다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  int  $priority  로딩 우선순위
     * @param  array<string, mixed>  $assets  매니페스트 assets 선언
     * @param  string  $strategy  로딩 전략
     */
    private function fakeExtensionWithAssets(string $identifier, int $priority, array $assets, string $strategy = 'global'): object
    {
        $ext = Mockery::mock();
        $ext->shouldReceive('hasAssets')->andReturn($assets !== []);
        $ext->shouldReceive('getIdentifier')->andReturn($identifier);
        $ext->shouldReceive('getAssetLoadingConfig')->andReturn([
            'strategy' => $strategy,
            'priority' => $priority,
            'dependencies' => [],
        ]);
        $ext->shouldReceive('getAssets')->andReturn($assets);
        $ext->shouldReceive('getBuiltAssetAbsolutePaths')->andReturn(
            array_map(fn () => $this->fixtureDir.'/missing-'.$identifier.'.out', $assets)
        );

        return $ext;
    }

    private function service(): ExtensionBundleService
    {
        return new ExtensionBundleService($this->moduleManager, $this->pluginManager);
    }

    /**
     * 선언 수는 **매니페스트 기준**이다 — 산출물 파일 존재 여부와 무관하다 (E2).
     *
     * 이 값이 "실제로 병합된 확장 수" 로 계산되면 dist 가 통째로 비어 있을 때
     * `기대 0 = 결과 0` 이 되어 장애가 정상(빈 200)으로 위장된다. 실측 A/B: dist 를
     * 비우면 수정 전 `modules/bundle/js` 가 빈 200, 수정 후 503 이다.
     *
     * @effects asset_declaration_count_reads_manifest_not_built_files
     */
    public function test_counts_asset_declaring_extensions_from_manifest_not_built_output(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-js' => $this->fakeExtensionWithAssets('ext-js', 10, ['js' => ['output' => 'dist/js/ext-js.iife.js']]),
            'ext-both' => $this->fakeExtensionWithAssets('ext-both', 20, [
                'js' => ['output' => 'dist/js/ext-both.iife.js'],
                'css' => ['output' => 'dist/css/ext-both.css'],
            ]),
            'ext-none' => $this->fakeExtensionWithAssets('ext-none', 30, []),
            // 병합 대상 모집단과 같은 필터 — global 전략이 아니면 세지 않는다
            'ext-layout' => $this->fakeExtensionWithAssets('ext-layout', 40, [
                'js' => ['output' => 'dist/js/ext-layout.iife.js'],
            ], 'layout'),
        ]);

        $service = $this->service();

        $this->assertSame(2, $service->countAssetDeclaringExtensions('module', 'js'));
        $this->assertSame(1, $service->countAssetDeclaringExtensions('module', 'css'));

        // 산출물은 하나도 존재하지 않는다 → 병합 결과는 빈 문자열.
        // "선언 > 0 && 결과 0" 이 곧 컨트롤러의 503 조건이다.
        $this->assertSame('', $service->getBundleFilePath('module', 'js', 12345));
    }

    public function test_orders_global_assets_by_priority_ascending(): void
    {
        $a = $this->writeFixture('a.js', '(function(){})()');
        $b = $this->writeFixture('b.js', '(function(){})()');
        $c = $this->writeFixture('c.js', '(function(){})()');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-c' => $this->fakeExtension('ext-c', 30, $c, null),
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
            'ext-b' => $this->fakeExtension('ext-b', 20, $b, null),
        ]);

        $ordered = $this->service()->getOrderedGlobalAssetPaths('module');

        $this->assertSame(['ext-a', 'ext-b', 'ext-c'], array_keys($ordered));
    }

    public function test_skips_non_global_strategy(): void
    {
        $g = $this->writeFixture('g.js', '(function(){})()');
        $l = $this->writeFixture('l.js', '(function(){})()');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-global' => $this->fakeExtension('ext-global', 100, $g, null, 'global'),
            'ext-layout' => $this->fakeExtension('ext-layout', 100, $l, null, 'layout'),
        ]);

        $ordered = $this->service()->getOrderedGlobalAssetPaths('module');

        $this->assertArrayHasKey('ext-global', $ordered);
        $this->assertArrayNotHasKey('ext-layout', $ordered);
    }

    public function test_js_bundle_joins_iife_with_semicolon_newline_separator(): void
    {
        // 세미콜론 없이 끝나는 IIFE 2개 (ecommerce 형태)
        $a = $this->writeFixture('a.js', '(function(){window.A=1})()');
        $b = $this->writeFixture('b.js', '(function(){window.B=2})()');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
            'ext-b' => $this->fakeExtension('ext-b', 20, $b, null),
        ]);

        $js = $this->service()->buildJsBundle('module');

        // 두 IIFE 가 모두 포함되고 `\n;\n` 구분자로 구분
        $this->assertStringContainsString('window.A=1', $js);
        $this->assertStringContainsString('window.B=2', $js);
        $this->assertStringContainsString("\n;\n", $js);
        // priority 순서 (a 가 b 보다 먼저)
        $this->assertLessThan(strpos($js, 'window.B=2'), strpos($js, 'window.A=1'));
    }

    public function test_prod_strips_source_mapping_url(): void
    {
        $this->app['env'] = 'production';
        app()->detectEnvironment(fn () => 'production');

        $a = $this->writeFixture('a.js', "(function(){})()\n//# sourceMappingURL=a.js.map");

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
        ]);

        $js = $this->service()->buildJsBundle('module');

        $this->assertStringNotContainsString('sourceMappingURL', $js);
    }

    public function test_dev_rewrites_source_mapping_url_to_asset_serving_path(): void
    {
        // 자산 URL 표기(경로형 vs `?file=` 쿼리형)는 사이트 설정이 정한다. 이 테스트가 보려는
        // 것은 "개발 환경에서 소스맵 URL 이 에셋 서빙 주소로 재작성되는가" 이므로, 개발 사이트가
        // 어느 모드를 쓰든 결과가 달라지지 않도록 경로형으로 고정한다.
        config(['g7_settings.core.general.asset_url_mode' => 'extension']);

        // 기본 testing 환경 = 비프로덕션 → dev rewrite 경로
        $a = $this->writeFixture('a.js', "(function(){})()\n//# sourceMappingURL=a.js.map");

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
        ]);

        $js = $this->service()->buildJsBundle('module');

        $this->assertStringContainsString('sourceMappingURL=/api/modules/assets/ext-a/dist/js/a.js.map', $js);
    }

    public function test_prod_strips_css_source_mapping_url(): void
    {
        $this->app['env'] = 'production';
        app()->detectEnvironment(fn () => 'production');

        // CSS 는 JS 와 주석 문법이 달라 블록 주석으로 맵을 참조한다.
        $css = $this->writeFixture('a.css', ".a{color:red}\n/*# sourceMappingURL=a.css.map */");

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, null, $css),
        ]);

        $bundle = $this->service()->buildCssBundle('module');

        $this->assertStringNotContainsString('sourceMappingURL', $bundle);
        // 스타일 본문은 보존되어야 한다 (주석만 제거)
        $this->assertStringContainsString('.a{color:red}', $bundle);
    }

    public function test_dev_keeps_css_source_mapping_url(): void
    {
        // 기본 testing 환경 = 비프로덕션 → 개발 디버깅을 위해 원본 유지
        $css = $this->writeFixture('a.css', ".a{color:red}\n/*# sourceMappingURL=a.css.map */");

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, null, $css),
        ]);

        $bundle = $this->service()->buildCssBundle('module');

        $this->assertStringContainsString('sourceMappingURL', $bundle);
    }

    public function test_per_extension_fault_tolerance_skips_missing_file(): void
    {
        $good = $this->writeFixture('good.js', '(function(){window.GOOD=1})()');
        $missing = $this->fixtureDir.'/does-not-exist.js';

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-missing' => $this->fakeExtension('ext-missing', 10, $missing, null),
            'ext-good' => $this->fakeExtension('ext-good', 20, $good, null),
        ]);

        $js = $this->service()->buildJsBundle('module');

        // 없는 파일은 skip, 정상 확장은 여전히 포함 (번들 전체 붕괴 안 함)
        $this->assertStringContainsString('window.GOOD=1', $js);
    }

    public function test_css_with_relative_url_is_excluded(): void
    {
        $safe = $this->writeFixture('safe.css', '.a{color:red}');
        $relative = $this->writeFixture('rel.css', '.b{background:url(./img/x.png)}');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-safe' => $this->fakeExtension('ext-safe', 10, null, $safe),
            'ext-rel' => $this->fakeExtension('ext-rel', 20, null, $relative),
        ]);

        $css = $this->service()->buildCssBundle('module');

        $this->assertStringContainsString('.a{color:red}', $css);
        $this->assertStringNotContainsString('url(./img/x.png)', $css);
    }

    public function test_empty_bundle_returns_empty_path(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);

        $path = $this->service()->getBundleFilePath('module', 'js', 12345);

        $this->assertSame('', $path);
    }

    public function test_prod_writes_versioned_cache_file_and_reuses_it(): void
    {
        $this->app['env'] = 'production';
        app()->detectEnvironment(fn () => 'production');

        $a = $this->writeFixture('a.js', '(function(){window.A=1})()');
        // getActiveModules 는 두 번 호출될 수 있으므로 안정적으로 반환
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
        ]);

        $svc = $this->service();
        $path1 = $svc->getBundleFilePath('module', 'js', 999);

        $this->assertNotSame('', $path1);
        $this->assertFileExists($path1);
        $this->assertStringContainsString('module.999.js', $path1);

        // 같은 version 재요청 → 동일 파일 (캐시 히트)
        $path2 = $svc->getBundleFilePath('module', 'js', 999);
        $this->assertSame($path1, $path2);
    }

    public function test_cleanup_removes_stale_version_bundles_only(): void
    {
        $bundleDir = storage_path('app/ext-bundles');
        File::ensureDirectoryExists($bundleDir);
        File::put($bundleDir.'/module.100.js', 'old');
        File::put($bundleDir.'/module.200.js', 'current');
        File::put($bundleDir.'/plugin.100.css', 'old');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);

        $deleted = $this->service()->cleanupStaleBundles(200);

        // version 200 외 파일만 삭제 (module.100.js, plugin.100.css = 2건)
        $this->assertSame(2, $deleted);
        $this->assertFileExists($bundleDir.'/module.200.js');
        $this->assertFileDoesNotExist($bundleDir.'/module.100.js');
        $this->assertFileDoesNotExist($bundleDir.'/plugin.100.css');
    }

    public function test_clear_bundles_by_type(): void
    {
        $bundleDir = storage_path('app/ext-bundles');
        File::ensureDirectoryExists($bundleDir);
        File::put($bundleDir.'/module.100.js', 'm');
        File::put($bundleDir.'/plugin.100.js', 'p');

        $deleted = $this->service()->clearBundles('module');

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($bundleDir.'/module.100.js');
        $this->assertFileExists($bundleDir.'/plugin.100.js');
    }

    /**
     * clearBundles 는 **현재 버전을 보존**한다 (E3).
     *
     * 현재 버전까지 지우면 같은 순간 서빙 중인 웹 요청이 "존재함" 판정 직후
     * `filemtime()` 에서 500 을 낸다(bump 직후 TOCTOU). `cleanupStaleBundles` 와
     * 정책이 갈라져 있던 것이 원인이었다.
     *
     * @effects clear_bundles_preserves_current_version
     */
    public function test_clear_bundles_preserves_current_version(): void
    {
        $bundleDir = storage_path('app/ext-bundles');
        File::ensureDirectoryExists($bundleDir);

        $current = $this->service()->getCurrentVersion();
        File::put($bundleDir."/module.{$current}.js", 'current');
        File::put($bundleDir.'/module.100.js', 'old');

        $deleted = $this->service()->clearBundles('module');

        $this->assertSame(1, $deleted);
        $this->assertFileExists(
            $bundleDir."/module.{$current}.js",
            '현재 버전이 삭제됐다 — 서빙 중인 요청이 filemtime 에서 500 을 낸다'
        );
        $this->assertFileDoesNotExist($bundleDir.'/module.100.js');
    }

    /**
     * GC 는 원자적 쓰기의 임시 파일(`*.tmp.{pid}`)도 정리한다 — 단 나이 가드를 지킨다 (E4).
     *
     * 종전에는 이 파일명이 번들 파일 패턴에 맞지 않아 GC 대상에서 통째로 빠졌고,
     * rename 이 실패한 만큼 영구 잔존했다(실측 560개).
     *
     * @effects cleanup_removes_stale_atomic_write_temp_files
     */
    public function test_cleanup_removes_stale_temp_bundle_files_only(): void
    {
        $bundleDir = storage_path('app/ext-bundles');
        File::ensureDirectoryExists($bundleDir);

        $fresh = $bundleDir.'/module.200.js.tmp.11111';
        $stale = $bundleDir.'/module.200.js.tmp.22222';
        File::put($fresh, 'writing now');
        File::put($stale, 'orphan');
        touch($stale, time() - 3600);
        clearstatcache();

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);

        try {
            $deleted = $this->service()->cleanupStaleBundles(200);

            $this->assertFileExists($fresh, '진행 중인 쓰기의 임시 파일이 삭제됐다');
            $this->assertFileDoesNotExist($stale, '고아 임시 파일이 정리되지 않았다 — 영구 누적된다');
            $this->assertSame(1, $deleted);
        } finally {
            // 번들 디렉토리는 테스트 간 공유된다 — 남기면 형제 케이스의 삭제 건수가 틀어진다.
            @unlink($fresh);
            @unlink($stale);
        }
    }

    public function test_plugin_bundle_orders_by_priority_gdpr_first_when_lowest(): void
    {
        // gdpr 가 priority 50 으로 최상단(제약 1 회귀 가드) — 이름 하드코딩 아닌 선언 결과
        $gdpr = $this->writeFixture('gdpr.js', '(function(){window.GDPR=1})()');
        $other = $this->writeFixture('other.js', '(function(){window.OTHER=1})()');

        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([
            'sirsoft-other' => $this->fakeExtension('sirsoft-other', 100, $other, null),
            'sirsoft-gdpr' => $this->fakeExtension('sirsoft-gdpr', 50, $gdpr, null),
        ]);

        $ordered = $this->service()->getOrderedGlobalAssetPaths('plugin');

        $this->assertSame('sirsoft-gdpr', array_key_first($ordered));

        $js = $this->service()->buildJsBundle('plugin');
        $this->assertLessThan(strpos($js, 'window.OTHER=1'), strpos($js, 'window.GDPR=1'));
    }
}
