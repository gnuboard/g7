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

    private function service(): ExtensionBundleService
    {
        return new ExtensionBundleService($this->moduleManager, $this->pluginManager);
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
        // 기본 testing 환경 = 비프로덕션 → dev rewrite 경로
        $a = $this->writeFixture('a.js', "(function(){})()\n//# sourceMappingURL=a.js.map");

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
        ]);

        $js = $this->service()->buildJsBundle('module');

        $this->assertStringContainsString('sourceMappingURL=/api/modules/assets/ext-a/dist/js/a.js.map', $js);
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
