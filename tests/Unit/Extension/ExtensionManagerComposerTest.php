<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ExtensionManager Composer 관련 메서드 테스트
 *
 * hasComposerDependencies, getComposerDependencies,
 * collectModuleAutoloads (vendor_autoloads), detectDuplicatePackages
 * 메서드의 동작을 검증합니다.
 */
class ExtensionManagerComposerTest extends TestCase
{
    use RefreshDatabase;

    private ExtensionManager $extensionManager;

    private ModuleManager $moduleManager;

    private PluginManager $pluginManager;

    /**
     * 테스트용 임시 디렉토리 경로
     */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->extensionManager = app(ExtensionManager::class);
        $this->moduleManager = app(ModuleManager::class);
        $this->pluginManager = app(PluginManager::class);
    }

    protected function tearDown(): void
    {
        // 테스트용 임시 디렉토리 정리
        foreach ($this->tempDirs as $dir) {
            if (File::exists($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 테스트용 모듈 디렉토리와 composer.json을 생성합니다.
     *
     * @param  string  $moduleName  모듈명
     * @param  array  $composerJson  composer.json 내용
     */
    private function createTestModule(string $moduleName, array $composerJson): void
    {
        $moduleDir = base_path("modules/{$moduleName}");
        File::ensureDirectoryExists($moduleDir);
        File::put($moduleDir.'/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT));
        $this->tempDirs[] = $moduleDir;
    }

    /**
     * 테스트용 플러그인 디렉토리와 composer.json을 생성합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @param  array  $composerJson  composer.json 내용
     */
    private function createTestPlugin(string $pluginName, array $composerJson): void
    {
        $pluginDir = base_path("plugins/{$pluginName}");
        File::ensureDirectoryExists($pluginDir);
        File::put($pluginDir.'/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT));
        $this->tempDirs[] = $pluginDir;
    }

    // ========================================================================
    // hasComposerDependencies 테스트
    // ========================================================================

    /**
     * php만 require하는 경우 외부 의존성이 없다고 판단합니다.
     */
    public function test_has_composer_dependencies_returns_false_for_php_only(): void
    {
        $this->createTestModule('test-phponly', [
            'name' => 'test/phponly',
            'require' => [
                'php' => '^8.2',
            ],
        ]);

        $this->assertFalse(
            $this->extensionManager->hasComposerDependencies('modules', 'test-phponly')
        );
    }

    /**
     * 외부 패키지가 있는 경우 true를 반환합니다.
     */
    public function test_has_composer_dependencies_returns_true_with_external_packages(): void
    {
        $this->createTestModule('test-withdeps', [
            'name' => 'test/withdeps',
            'require' => [
                'php' => '^8.2',
                'vendor/package' => '^1.0',
            ],
        ]);

        $this->assertTrue(
            $this->extensionManager->hasComposerDependencies('modules', 'test-withdeps')
        );
    }

    /**
     * composer.json이 없는 경우 false를 반환합니다.
     */
    public function test_has_composer_dependencies_returns_false_without_composer_json(): void
    {
        $this->assertFalse(
            $this->extensionManager->hasComposerDependencies('modules', 'nonexistent-module')
        );
    }

    /**
     * ext-* 확장만 있는 경우 외부 의존성이 없다고 판단합니다.
     */
    public function test_has_composer_dependencies_excludes_ext_packages(): void
    {
        $this->createTestModule('test-extonly', [
            'name' => 'test/extonly',
            'require' => [
                'php' => '^8.2',
                'ext-json' => '*',
                'ext-mbstring' => '*',
            ],
        ]);

        $this->assertFalse(
            $this->extensionManager->hasComposerDependencies('modules', 'test-extonly')
        );
    }

    // ========================================================================
    // hasComposerDependenciesAt 테스트 (경로 기반)
    // ========================================================================

    /**
     * 지정된 경로의 composer.json에 외부 패키지가 있으면 true를 반환합니다.
     */
    public function test_has_composer_dependencies_at_returns_true_with_external_packages(): void
    {
        $tempDir = storage_path('app/temp/test-deps-at-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        File::put($tempDir.'/composer.json', json_encode([
            'name' => 'test/deps-at',
            'require' => [
                'php' => '^8.2',
                'vendor/some-package' => '^1.0',
            ],
        ]));
        $this->tempDirs[] = $tempDir;

        $this->assertTrue(
            $this->extensionManager->hasComposerDependenciesAt($tempDir)
        );
    }

    /**
     * 지정된 경로의 composer.json에 php/ext-*만 있으면 false를 반환합니다.
     */
    public function test_has_composer_dependencies_at_returns_false_for_php_and_ext_only(): void
    {
        $tempDir = storage_path('app/temp/test-no-deps-at-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        File::put($tempDir.'/composer.json', json_encode([
            'name' => 'test/no-deps-at',
            'require' => [
                'php' => '^8.2',
                'ext-json' => '*',
            ],
        ]));
        $this->tempDirs[] = $tempDir;

        $this->assertFalse(
            $this->extensionManager->hasComposerDependenciesAt($tempDir)
        );
    }

    /**
     * 지정된 경로에 composer.json이 없으면 false를 반환합니다.
     */
    public function test_has_composer_dependencies_at_returns_false_without_composer_json(): void
    {
        $tempDir = storage_path('app/temp/test-no-composer-'.uniqid());
        File::ensureDirectoryExists($tempDir);
        $this->tempDirs[] = $tempDir;

        $this->assertFalse(
            $this->extensionManager->hasComposerDependenciesAt($tempDir)
        );
    }

    /**
     * _pending 경로에서도 정상적으로 동작합니다.
     */
    public function test_has_composer_dependencies_at_works_with_pending_path(): void
    {
        $pendingDir = base_path('modules/_pending/test-pending-deps-'.uniqid());
        File::ensureDirectoryExists($pendingDir);
        File::put($pendingDir.'/composer.json', json_encode([
            'name' => 'test/pending-deps',
            'require' => [
                'php' => '^8.2',
                'guzzlehttp/guzzle' => '^7.0',
            ],
        ]));
        $this->tempDirs[] = $pendingDir;

        $this->assertTrue(
            $this->extensionManager->hasComposerDependenciesAt($pendingDir)
        );
    }

    // ========================================================================
    // getComposerDependencies 테스트
    // ========================================================================

    /**
     * 외부 패키지만 반환합니다 (php, ext-* 제외).
     */
    public function test_get_composer_dependencies_returns_external_packages_only(): void
    {
        $this->createTestModule('test-mixed', [
            'name' => 'test/mixed',
            'require' => [
                'php' => '^8.2',
                'ext-json' => '*',
                'vendor/package-a' => '^1.0',
                'vendor/package-b' => '^2.0',
            ],
        ]);

        $deps = $this->extensionManager->getComposerDependencies('modules', 'test-mixed');

        $this->assertCount(2, $deps);
        $this->assertArrayHasKey('vendor/package-a', $deps);
        $this->assertArrayHasKey('vendor/package-b', $deps);
        $this->assertArrayNotHasKey('php', $deps);
        $this->assertArrayNotHasKey('ext-json', $deps);
    }

    /**
     * require 섹션이 없는 경우 빈 배열을 반환합니다.
     */
    public function test_get_composer_dependencies_returns_empty_without_require(): void
    {
        $this->createTestModule('test-norequire', [
            'name' => 'test/norequire',
        ]);

        $deps = $this->extensionManager->getComposerDependencies('modules', 'test-norequire');

        $this->assertEmpty($deps);
    }

    /**
     * 플러그인에서도 동일하게 동작합니다.
     */
    public function test_get_composer_dependencies_works_for_plugins(): void
    {
        $this->createTestPlugin('test-plugin', [
            'name' => 'test/plugin',
            'require' => [
                'php' => '^8.2',
                'stripe/stripe-php' => '^12.0',
            ],
        ]);

        $deps = $this->extensionManager->getComposerDependencies('plugins', 'test-plugin');

        $this->assertCount(1, $deps);
        $this->assertArrayHasKey('stripe/stripe-php', $deps);
    }

    // ========================================================================
    // detectDuplicatePackages 테스트
    // ========================================================================

    /**
     * 여러 확장이 동일 패키지를 사용하는 경우 감지합니다.
     */
    public function test_detect_duplicate_packages_finds_conflicts(): void
    {
        $this->createTestModule('test-dup-a', [
            'name' => 'test/dup-a',
            'require' => [
                'php' => '^8.2',
                'shared/package' => '^1.0',
            ],
        ]);

        $this->createTestModule('test-dup-b', [
            'name' => 'test/dup-b',
            'require' => [
                'php' => '^8.2',
                'shared/package' => '^2.0',
            ],
        ]);

        $duplicates = $this->extensionManager->detectDuplicatePackages();

        $this->assertArrayHasKey('shared/package', $duplicates);
        $this->assertCount(2, $duplicates['shared/package']);
        $this->assertContains('modules/test-dup-a', $duplicates['shared/package']);
        $this->assertContains('modules/test-dup-b', $duplicates['shared/package']);
    }

    /**
     * 중복이 없는 경우 빈 배열을 반환합니다.
     */
    public function test_detect_duplicate_packages_returns_empty_without_conflicts(): void
    {
        $this->createTestModule('test-unique-a', [
            'name' => 'test/unique-a',
            'require' => [
                'php' => '^8.2',
                'vendor/package-a' => '^1.0',
            ],
        ]);

        $this->createTestModule('test-unique-b', [
            'name' => 'test/unique-b',
            'require' => [
                'php' => '^8.2',
                'vendor/package-b' => '^1.0',
            ],
        ]);

        $duplicates = $this->extensionManager->detectDuplicatePackages();

        // 기존 모듈(sirsoft-ecommerce 등)의 중복은 무시하고, 테스트 모듈 간 중복만 확인
        $testDuplicates = array_filter($duplicates, function ($users) {
            return count(array_filter($users, fn ($u) => str_contains($u, 'test-unique'))) > 1;
        });

        $this->assertEmpty($testDuplicates);
    }

    // ========================================================================
    // collectModuleAutoloads - vendor_autoloads 테스트
    // ========================================================================

    /**
     * vendor/autoload.php가 존재하면 vendor_autoloads에 포함됩니다.
     */
    public function test_collect_module_autoloads_includes_vendor_autoloads(): void
    {
        // 설치된 모듈을 DB에 생성
        \App\Models\Module::create([
            'identifier' => 'test-vendorautoload',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => \App\Enums\ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트 모듈', 'en' => 'Test module'],
        ]);

        // 모듈 디렉토리에 composer.json과 vendor/autoload.php 생성
        $moduleDir = base_path('modules/test-vendorautoload');
        File::ensureDirectoryExists($moduleDir.'/vendor');
        File::put($moduleDir.'/composer.json', json_encode([
            'name' => 'test/vendorautoload',
            'autoload' => ['psr-4' => ['Test\\Vendorautoload\\' => 'src/']],
            'require' => ['php' => '^8.2', 'vendor/pkg' => '^1.0'],
        ]));
        File::put($moduleDir.'/vendor/autoload.php', '<?php // autoload');
        $this->tempDirs[] = $moduleDir;

        $reflection = new \ReflectionMethod($this->extensionManager, 'collectModuleAutoloads');
        $result = $reflection->invoke($this->extensionManager);

        $this->assertArrayHasKey('vendor_autoloads', $result);
        $this->assertContains(
            'modules/test-vendorautoload/vendor/autoload.php',
            $result['vendor_autoloads']
        );
    }

    /**
     * vendor/autoload.php가 없으면 vendor_autoloads에 포함되지 않습니다.
     */
    public function test_collect_module_autoloads_excludes_missing_vendor(): void
    {
        // 설치된 모듈을 DB에 생성
        \App\Models\Module::create([
            'identifier' => 'test-novendor',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => \App\Enums\ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트 모듈', 'en' => 'Test module'],
        ]);

        // 모듈 디렉토리에 composer.json만 생성 (vendor 없음)
        $moduleDir = base_path('modules/test-novendor');
        File::ensureDirectoryExists($moduleDir);
        File::put($moduleDir.'/composer.json', json_encode([
            'name' => 'test/novendor',
            'autoload' => ['psr-4' => ['Test\\Novendor\\' => 'src/']],
        ]));
        $this->tempDirs[] = $moduleDir;

        $reflection = new \ReflectionMethod($this->extensionManager, 'collectModuleAutoloads');
        $result = $reflection->invoke($this->extensionManager);

        $vendorAutoloads = array_filter(
            $result['vendor_autoloads'],
            fn ($path) => str_contains($path, 'test-novendor')
        );

        $this->assertEmpty($vendorAutoloads);
    }

    // ========================================================================
    // getVendorDirectoryInfo 테스트
    // ========================================================================

    /**
     * vendor 디렉토리와 composer.lock이 모두 있으면 정보를 반환합니다.
     */
    public function test_get_vendor_directory_info_returns_info_with_vendor_and_lock(): void
    {
        $moduleDir = base_path('modules/test-vendor-info');
        File::ensureDirectoryExists($moduleDir.'/vendor/some-package');
        File::put($moduleDir.'/vendor/some-package/file.php', '<?php // test');
        File::put($moduleDir.'/composer.lock', '{"packages":[]}');
        $this->tempDirs[] = $moduleDir;

        $reflection = new \ReflectionMethod($this->moduleManager, 'getVendorDirectoryInfo');
        $result = $reflection->invoke($this->moduleManager, 'modules', 'test-vendor-info');

        $this->assertNotNull($result);
        $this->assertCount(2, $result['items']);
        $this->assertEquals('vendor/', $result['items'][0]['name']);
        $this->assertEquals('composer.lock', $result['items'][1]['name']);
        $this->assertGreaterThan(0, $result['total_size_bytes']);
        $this->assertNotEmpty($result['total_size_formatted']);
    }

    /**
     * vendor만 있고 composer.lock이 없으면 vendor만 반환합니다.
     */
    public function test_get_vendor_directory_info_returns_vendor_only(): void
    {
        $moduleDir = base_path('modules/test-vendor-only');
        File::ensureDirectoryExists($moduleDir.'/vendor');
        File::put($moduleDir.'/vendor/autoload.php', '<?php // autoload');
        $this->tempDirs[] = $moduleDir;

        $reflection = new \ReflectionMethod($this->moduleManager, 'getVendorDirectoryInfo');
        $result = $reflection->invoke($this->moduleManager, 'modules', 'test-vendor-only');

        $this->assertNotNull($result);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('vendor/', $result['items'][0]['name']);
    }

    /**
     * vendor도 composer.lock도 없으면 null을 반환합니다.
     */
    public function test_get_vendor_directory_info_returns_null_without_vendor(): void
    {
        $moduleDir = base_path('modules/test-no-vendor-dir');
        File::ensureDirectoryExists($moduleDir);
        File::put($moduleDir.'/composer.json', '{"name":"test/no-vendor"}');
        $this->tempDirs[] = $moduleDir;

        $reflection = new \ReflectionMethod($this->moduleManager, 'getVendorDirectoryInfo');
        $result = $reflection->invoke($this->moduleManager, 'modules', 'test-no-vendor-dir');

        $this->assertNull($result);
    }

    /**
     * 플러그인에서도 동일하게 동작합니다.
     */
    public function test_get_vendor_directory_info_works_for_plugins(): void
    {
        $pluginDir = base_path('plugins/test-plugin-vendor');
        File::ensureDirectoryExists($pluginDir.'/vendor');
        File::put($pluginDir.'/vendor/autoload.php', '<?php // autoload');
        File::put($pluginDir.'/composer.lock', '{}');
        $this->tempDirs[] = $pluginDir;

        $reflection = new \ReflectionMethod($this->pluginManager, 'getVendorDirectoryInfo');
        $result = $reflection->invoke($this->pluginManager, 'plugins', 'test-plugin-vendor');

        $this->assertNotNull($result);
        $this->assertCount(2, $result['items']);
    }

    // ========================================================================
    // deleteVendorDirectory 테스트
    // ========================================================================

    /**
     * vendor 디렉토리와 composer.lock을 삭제합니다.
     */
    public function test_delete_vendor_directory_removes_vendor_and_lock(): void
    {
        $moduleDir = base_path('modules/test-delete-vendor');
        File::ensureDirectoryExists($moduleDir.'/vendor/pkg');
        File::put($moduleDir.'/vendor/pkg/file.php', '<?php');
        File::put($moduleDir.'/composer.lock', '{}');
        File::put($moduleDir.'/composer.json', '{"name":"test/delete"}');
        $this->tempDirs[] = $moduleDir;

        $this->assertTrue(File::isDirectory($moduleDir.'/vendor'));
        $this->assertTrue(File::exists($moduleDir.'/composer.lock'));

        $reflection = new \ReflectionMethod($this->moduleManager, 'deleteVendorDirectory');
        $reflection->invoke($this->moduleManager, 'modules', 'test-delete-vendor');

        $this->assertFalse(File::isDirectory($moduleDir.'/vendor'));
        $this->assertFalse(File::exists($moduleDir.'/composer.lock'));
        // composer.json은 삭제되지 않음
        $this->assertTrue(File::exists($moduleDir.'/composer.json'));
    }

    /**
     * vendor가 없어도 에러 없이 동작합니다.
     */
    public function test_delete_vendor_directory_handles_missing_vendor(): void
    {
        $moduleDir = base_path('modules/test-delete-no-vendor');
        File::ensureDirectoryExists($moduleDir);
        File::put($moduleDir.'/composer.json', '{"name":"test/no-vendor"}');
        $this->tempDirs[] = $moduleDir;

        // 예외 없이 실행되어야 함
        $reflection = new \ReflectionMethod($this->moduleManager, 'deleteVendorDirectory');
        $reflection->invoke($this->moduleManager, 'modules', 'test-delete-no-vendor');

        $this->assertTrue(true); // 예외 없이 통과
    }

    // ========================================================================
    // getModuleUninstallInfo - vendor_directory 포함 테스트
    // ========================================================================

    /**
     * getModuleUninstallInfo 응답에 vendor_directory 키가 포함됩니다.
     *
     * 테스트용 임시 모듈(stub)을 active 디렉토리에 생성하여 검증합니다.
     * (_bundled 이동 후 active 디렉토리에 module.php가 필요하므로)
     */
    public function test_get_module_uninstall_info_includes_vendor_directory(): void
    {
        $moduleName = 'test-vendorcheck';
        $moduleDir = base_path("modules/{$moduleName}");

        // 테스트용 stub 모듈 생성 (active 디렉토리에 module.php 필수)
        File::ensureDirectoryExists($moduleDir.'/vendor');
        File::put($moduleDir.'/module.php', '<?php namespace Modules\Test\Vendorcheck; use App\Extension\AbstractModule; class Module extends AbstractModule {}');
        File::put($moduleDir.'/module.json', json_encode([
            'identifier' => $moduleName,
            'version' => '1.0.0',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
        ]));
        File::put($moduleDir.'/vendor/autoload.php', '<?php // test autoload');
        File::put($moduleDir.'/composer.lock', '{"packages":[]}');
        $this->tempDirs[] = $moduleDir;

        // DB 레코드 생성
        \App\Models\Module::create([
            'identifier' => $moduleName,
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => \App\Enums\ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트 모듈', 'en' => 'Test module'],
        ]);

        // 모듈 로드
        $this->moduleManager->loadModules();

        $info = $this->moduleManager->getModuleUninstallInfo($moduleName);

        $this->assertNotNull($info);
        $this->assertArrayHasKey('vendor_directory', $info);
        $this->assertNotNull($info['vendor_directory']);
        $this->assertArrayHasKey('items', $info['vendor_directory']);
        $this->assertArrayHasKey('total_size_bytes', $info['vendor_directory']);
    }
}
