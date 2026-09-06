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
    private function fakeExtension(string $identifier, int $priority, ?string $jsPath, ?string $cssPath, string $strategy = 'global', ?string $cssRelPath = null): object
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

        // 확장 루트 기준 상대 경로 — CSS 상대 참조 해석의 기준점
        $relative = [];
        if ($cssRelPath !== null) {
            $relative['css'] = $cssRelPath;
        }
        $ext->shouldReceive('getBuiltAssetPaths')->andReturn($relative);

        return $ext;
    }

    /**
     * 에셋 **매니페스트 선언**(`getAssets()`)을 노출하는 가짜 확장 인스턴스를 만든다.
     *
     * 산출물 경로(`getBuiltAssetAbsolutePaths`)는 기본적으로 존재하지 않는 파일을 가리킨다 —
     * "선언은 있는데 산출물이 없다"(dist 소실) 상태를 그대로 재현하기 위해서다.
     *
     * 선언 축 게터(`getDeclaredAssetAbsolutePaths`)는 파일 존재와 무관하게 선언된 kind 마다
     * 경로를 돌려준다. `$declaredPaths` 로 그 경로를 지정하면 실제로 만들어 둔 fixture 파일을
     * 가리키게 해 "존재하되 비어 있음" 상태를 만들 수 있다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  int  $priority  로딩 우선순위
     * @param  array<string, mixed>  $assets  매니페스트 assets 선언
     * @param  string  $strategy  로딩 전략
     * @param  array<string, string>|null  $declaredPaths  kind => 절대 경로 (미지정 시 fixtureDir 의 부재 경로)
     */
    private function fakeExtensionWithAssets(
        string $identifier,
        int $priority,
        array $assets,
        string $strategy = 'global',
        ?array $declaredPaths = null
    ): object {
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
        $ext->shouldReceive('getBuiltAssetPaths')->andReturn(
            array_map(fn () => 'dist/css/module.css', $assets)
        );

        $declared = $declaredPaths ?? array_combine(
            array_keys($assets),
            array_map(fn ($kind) => $this->fixtureDir.'/declared-'.$identifier.'.'.$kind, array_keys($assets))
        );
        $ext->shouldReceive('getDeclaredAssetAbsolutePaths')->andReturn($declared);

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
        // 이 값은 **선언 축**이다 — 컨트롤러의 503 판정은 소실 축(findMissingDeclaredAssets)이
        // 한다. 선언 > 0 그 자체는 장애 조건이 아니다(존재하되 비어 있으면 정상 빈 200).
        $this->assertSame('', $service->getBundleFilePath('module', 'js', 12345));
    }

    /**
     * 실행 순서는 오직 manifest `loading.priority` 오름차순이다 — 확장 이름을 지목하는
     * 분기를 두지 않는다.
     *
     * @effects ordered_by_priority_ascending_only_no_name_hardcode
     */
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

    /**
     * global 전략이 아닌 확장은 번들 모집단이 아니다 (레이아웃/지연 로딩이 따로 처리한다).
     *
     * @effects non_global_strategy_excluded_from_bundle
     */
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

    /**
     * IIFE 사이는 `\n;\n` 로 잇는다 — 구분자가 없으면 ASI 경계가 깨져 번들 전체가 죽는다.
     *
     * @effects js_iife_joined_with_semicolon_newline_separator
     */
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

    /**
     * @effects prod_strips_source_mapping_url
     */
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

    /**
     * @effects dev_rewrites_source_mapping_url_to_asset_serving_path
     */
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

    /**
     * 한 확장의 읽기 실패가 번들 전체를 소실시키지 않는다 — 그 확장만 빠지고 나머지는 병합된다.
     *
     * @effects per_extension_fault_tolerance_skips_missing_file_keeps_rest
     */
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

    /**
     * 상대 참조를 가진 CSS 는 **제외되지 않고 치환되어** 병합된다.
     *
     * 종전 계약은 그 확장을 번들에서 통째로 제외하는 것이었고 주석은 "개별 폴백 유지" 라고
     * 적었지만, 번들 URL 이 내려오면 프론트는 개별 로딩을 아예 타지 않는다
     * (TemplateApp.loadExtensionAssets). 즉 제외 = 그 확장 스타일이 하나도 적용되지 않음
     * 이었고, 오류도 로그 흔적도 화면 경고도 남지 않았다.
     *
     * @effects css_with_relative_url_rewritten_not_excluded
     */
    public function test_css_with_relative_url_is_rewritten_not_excluded(): void
    {
        $safe = $this->writeFixture('safe.css', '.a{color:red}');
        $relative = $this->writeFixture('rel.css', '.b{background:url(./img/x.png)}');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-safe' => $this->fakeExtension('ext-safe', 10, null, $safe, cssRelPath: 'dist/css/module.css'),
            'ext-rel' => $this->fakeExtension('ext-rel', 20, null, $relative, cssRelPath: 'dist/css/module.css'),
        ]);

        $css = $this->service()->buildCssBundle('module');

        // 두 확장 모두 병합에 남는다 — 제외가 사라졌다는 것이 이 축의 핵심이다.
        $this->assertStringContainsString('.a{color:red}', $css);
        $this->assertStringContainsString('.b{background:', $css);

        // 상대 참조는 그대로 나가지 않는다 (그 주소는 병합본 기준으로 풀려 404 가 된다).
        $this->assertStringNotContainsString('url(./img/x.png)', $css);

        // 치환 결과는 그 확장의 절대 자산 URL 이며, CSS 가 놓인 디렉토리 기준으로 풀린다.
        $this->assertStringContainsString('/api/modules/assets/ext-rel', $css);
        $this->assertStringContainsString('dist/css/img/x.png', rawurldecode($css));
    }

    /**
     * 절대·루트상대·data URI 참조는 병합에서도 손대지 않는다.
     */
    public function test_css_bundle_leaves_absolute_references_untouched(): void
    {
        $path = $this->writeFixture('abs.css',
            '.a{background:url(https://cdn.example.com/x.png)}'
            .'.b{background:url(/build/ext/1/y.png)}'
            .'.c{background:url(data:image/gif;base64,AA==)}'
        );

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-abs' => $this->fakeExtension('ext-abs', 10, null, $path, cssRelPath: 'dist/css/module.css'),
        ]);

        $css = $this->service()->buildCssBundle('module');

        $this->assertStringContainsString('url(https://cdn.example.com/x.png)', $css);
        $this->assertStringContainsString('url(/build/ext/1/y.png)', $css);
        $this->assertStringContainsString('url(data:image/gif;base64,AA==)', $css);
    }

    /**
     * @effects empty_bundle_returns_empty_path_and_empty_ok_response
     */
    public function test_empty_bundle_returns_empty_path(): void
    {
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);

        $path = $this->service()->getBundleFilePath('module', 'js', 12345);

        $this->assertSame('', $path);
    }

    /**
     * @effects prod_writes_versioned_cache_file_and_reuses_on_same_version
     */
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

    /**
     * 비프로덕션은 같은 version 이어도 매 요청 다시 병합한다 (캐시 재사용 없음).
     *
     * 프로덕션은 같은 version 캐시가 있으면 그대로 재사용하지만, 비프로덕션에서 그러면
     * 개발 중 rebuild 가 반영되지 않는다. 소스를 바꿨는데 화면이 그대로인 상태는 오류도
     * 로그도 남지 않아 원인을 짚을 수 없다.
     *
     * @effects non_production_rebuilds_every_request_no_disk_cache
     */
    public function test_non_production_rebuilds_every_request_without_cache_reuse(): void
    {
        $this->assertFalse(app()->environment('production'));

        $a = $this->writeFixture('nonprod.js', '(function(){window.A=1})()');
        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-a' => $this->fakeExtension('ext-a', 10, $a, null),
        ]);

        $svc = $this->service();

        $path1 = $svc->getBundleFilePath('module', 'js', 4242);
        $this->assertStringContainsString('window.A=1', (string) file_get_contents($path1));

        // 소스를 바꾸고 **같은 version** 으로 다시 요청 → 재병합되어야 한다
        File::put($a, '(function(){window.A=2})()');
        $path2 = $svc->getBundleFilePath('module', 'js', 4242);

        $this->assertSame($path1, $path2);
        $this->assertStringContainsString('window.A=2', (string) file_get_contents($path2));

        @unlink($path1);
    }

    /**
     * @effects cleanup_removes_stale_version_bundles_only
     */
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

    /**
     * @effects clear_bundles_by_type_scopes_to_prefix
     */
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

    /**
     * @effects plugin_bundle_gdpr_first_when_priority_lowest
     */
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

    /**
     * 선언한 산출물이 **존재하되 0바이트**면 소실 목록에 넣지 않는다.
     *
     * 스타일 소스가 자리표시 주석뿐인 확장은 0바이트 CSS 를 내보내는 정당한 상태다.
     * 이것을 소실로 세면 그 확장만 설치된 기본 구성이 통째로 503 이 된다.
     *
     * @effects empty_result_with_present_empty_artifacts_returns_200
     */
    public function test_find_missing_declared_assets_ignores_present_zero_byte_files(): void
    {
        $cssPath = $this->writeFixture('present-empty.css', '');

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-empty-css' => $this->fakeExtensionWithAssets(
                'ext-empty-css',
                100,
                ['css' => ['output' => 'dist/css/module.css']],
                'global',
                ['css' => $cssPath]
            ),
        ]);

        $service = $this->service();

        $this->assertSame(0, filesize($cssPath));
        $this->assertSame([], $service->findMissingDeclaredAssets('module', 'css'));
        // 선언 축은 그대로 1 — 두 축이 다른 것을 잰다
        $this->assertSame(1, $service->countAssetDeclaringExtensions('module', 'css'));
        // 병합 결과는 빈 문자열이지만 장애가 아니다
        $this->assertSame('', $service->buildCssBundle('module'));
    }

    /**
     * 선언한 산출물이 **없으면** 그 절대 경로를 소실 목록으로 돌려준다.
     *
     * non-global 전략 확장은 병합 모집단이 아니므로 소실 판정에서도 제외된다 —
     * 두 판정이 같은 모집단을 써야 한쪽만 장애로 보는 어긋남이 생기지 않는다.
     *
     * @effects empty_result_with_missing_declared_artifact_returns_503
     */
    public function test_find_missing_declared_assets_lists_absent_files(): void
    {
        $absent = $this->fixtureDir.'/absent-module.css';
        $absentLayout = $this->fixtureDir.'/absent-layout.css';
        @unlink($absent);
        @unlink($absentLayout);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([
            'ext-gone' => $this->fakeExtensionWithAssets(
                'ext-gone',
                100,
                ['css' => ['output' => 'dist/css/module.css']],
                'global',
                ['css' => $absent]
            ),
            'ext-layout' => $this->fakeExtensionWithAssets(
                'ext-layout',
                100,
                ['css' => ['output' => 'dist/css/module.css']],
                'layout',
                ['css' => $absentLayout]
            ),
        ]);

        $missing = $this->service()->findMissingDeclaredAssets('module', 'css');

        $this->assertSame([$absent], $missing);
        $this->assertNotContains($absentLayout, $missing);
    }

    /**
     * 플러그인 축도 같은 판정을 쓴다 — 모집단만 다르다.
     *
     * @effects bundle_decision_is_shared_across_extension_types
     */
    public function test_find_missing_declared_assets_covers_plugins(): void
    {
        $absent = $this->fixtureDir.'/absent-plugin.js';
        @unlink($absent);
        $present = $this->writeFixture('present-plugin.js', '');

        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([
            'plg-gone' => $this->fakeExtensionWithAssets(
                'plg-gone',
                100,
                ['js' => ['output' => 'dist/js/plugin.iife.js']],
                'global',
                ['js' => $absent]
            ),
            'plg-empty' => $this->fakeExtensionWithAssets(
                'plg-empty',
                100,
                ['js' => ['output' => 'dist/js/plugin.iife.js']],
                'global',
                ['js' => $present]
            ),
        ]);

        $this->assertSame([$absent], $this->service()->findMissingDeclaredAssets('plugin', 'js'));
    }
}
