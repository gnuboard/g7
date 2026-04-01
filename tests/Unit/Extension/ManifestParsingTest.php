<?php

namespace Tests\Unit\Extension;

use Tests\TestCase;

/**
 * 매니페스트 파싱 테스트
 *
 * AbstractModule/AbstractPlugin의 loadManifest()가 JSON 매니페스트를
 * 올바르게 파싱하고 메타데이터를 반환하는지 검증합니다.
 */
class ManifestParsingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/g7_manifest_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // 임시 디렉토리 정리
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * 임시 디렉토리 재귀 삭제
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * 모듈 테스트용 ConcreteModule 인스턴스를 생성합니다.
     */
    private function createTestModule(string $dir, ?array $manifest = null): object
    {
        // module.json 생성
        if ($manifest !== null) {
            file_put_contents($dir.'/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // module.php 파일 생성 (동적 클래스)
        $className = 'TestModule_'.md5($dir);
        if (! class_exists($className)) {
            $code = <<<PHP
class {$className} extends \App\Extension\AbstractModule {
    protected function getModulePath(): string {
        return '{$dir}';
    }
}
PHP;
            eval($code);
        }

        return new $className;
    }

    /**
     * 플러그인 테스트용 ConcretePlugin 인스턴스를 생성합니다.
     */
    private function createTestPlugin(string $dir, ?array $manifest = null): object
    {
        // plugin.json 생성
        if ($manifest !== null) {
            file_put_contents($dir.'/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // plugin.php 파일 생성 (동적 클래스)
        $className = 'TestPlugin_'.md5($dir);
        if (! class_exists($className)) {
            $code = <<<PHP
class {$className} extends \App\Extension\AbstractPlugin {
    protected function getPluginPath(): string {
        return '{$dir}';
    }
}
PHP;
            eval($code);
        }

        return new $className;
    }

    // ===== AbstractModule loadManifest() 테스트 =====

    /**
     * module.json에서 이름을 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_name_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'identifier' => 'test-module',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
            'version' => '1.0.0',
            'description' => ['ko' => '설명', 'en' => 'Description'],
        ]);

        $this->assertEquals(['ko' => '테스트 모듈', 'en' => 'Test Module'], $module->getName());
    }

    /**
     * module.json에서 버전을 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_version_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'version' => '2.3.1',
        ]);

        $this->assertEquals('2.3.1', $module->getVersion());
    }

    /**
     * module.json에서 설명을 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_description_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'description' => ['ko' => '한국어 설명', 'en' => 'English description'],
        ]);

        $this->assertEquals(['ko' => '한국어 설명', 'en' => 'English description'], $module->getDescription());
    }

    /**
     * module.json에서 github_url을 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_github_url_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'github_url' => 'https://github.com/test/module',
        ]);

        $this->assertEquals('https://github.com/test/module', $module->getGithubUrl());
    }

    /**
     * module.json에서 g7_version을 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_required_core_version_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'g7_version' => '>=1.0.0',
        ]);

        $this->assertEquals('>=1.0.0', $module->getRequiredCoreVersion());
    }

    /**
     * module.json에서 assets를 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_assets_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'assets' => [
                'js' => ['entry' => 'resources/js/index.ts', 'output' => 'dist/js/module.iife.js'],
                'handlers' => true,
            ],
        ]);

        $assets = $module->getAssets();
        $this->assertEquals('resources/js/index.ts', $assets['js']['entry']);
        $this->assertTrue($assets['handlers']);
    }

    /**
     * module.json에서 loading 설정을 올바르게 파싱하는지 확인합니다.
     */
    public function test_module_reads_loading_config_from_manifest(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'loading' => [
                'strategy' => 'lazy',
                'priority' => 50,
            ],
        ]);

        $config = $module->getAssetLoadingConfig();
        $this->assertEquals('lazy', $config['strategy']);
        $this->assertEquals(50, $config['priority']);
        $this->assertEquals([], $config['dependencies']);
    }

    /**
     * module.json이 없는 경우 폴백 값이 올바른지 확인합니다.
     */
    public function test_module_falls_back_when_manifest_missing(): void
    {
        $module = $this->createTestModule($this->tempDir); // manifest 없음

        // identifier는 디렉토리명에서 추출 (tempDir basename)
        $this->assertEquals(basename($this->tempDir), $module->getName());
        $this->assertEquals('0.0.0', $module->getVersion());
        $this->assertEquals('', $module->getDescription());
        $this->assertNull($module->getGithubUrl());
        $this->assertNull($module->getRequiredCoreVersion());
        $this->assertEquals([], $module->getAssets());
    }

    /**
     * loadManifest()가 결과를 캐싱하는지 확인합니다.
     */
    public function test_module_manifest_is_cached(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'name' => ['ko' => '최초', 'en' => 'First'],
            'version' => '1.0.0',
        ]);

        // 첫 번째 호출
        $this->assertEquals(['ko' => '최초', 'en' => 'First'], $module->getName());

        // JSON 파일 수정
        file_put_contents($this->tempDir.'/module.json', json_encode([
            'name' => ['ko' => '수정됨', 'en' => 'Modified'],
            'version' => '2.0.0',
        ]));

        // 캐시되어 있으므로 이전 값 반환
        $this->assertEquals(['ko' => '최초', 'en' => 'First'], $module->getName());
        $this->assertEquals('1.0.0', $module->getVersion());
    }

    /**
     * module.json의 필드가 부분적으로만 있는 경우 올바르게 폴백하는지 확인합니다.
     */
    public function test_module_partial_manifest_uses_fallbacks(): void
    {
        $module = $this->createTestModule($this->tempDir, [
            'name' => ['ko' => '이름만', 'en' => 'Name Only'],
            // version, description 없음
        ]);

        $this->assertEquals(['ko' => '이름만', 'en' => 'Name Only'], $module->getName());
        $this->assertEquals('0.0.0', $module->getVersion());
        $this->assertEquals('', $module->getDescription());
    }

    /**
     * upgrades()가 빈 배열을 반환하는지 확인합니다 (upgrades/ 디렉토리 없는 경우).
     */
    public function test_module_upgrades_returns_empty_without_directory(): void
    {
        $module = $this->createTestModule($this->tempDir, []);

        $this->assertEquals([], $module->upgrades());
    }

    // ===== AbstractPlugin loadManifest() 테스트 =====

    /**
     * plugin.json에서 이름을 올바르게 파싱하는지 확인합니다.
     */
    public function test_plugin_reads_name_from_manifest(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'identifier' => 'test-plugin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 플러그인', 'en' => 'Test Plugin'],
            'version' => '1.0.0',
            'description' => ['ko' => '설명', 'en' => 'Description'],
        ]);

        $this->assertEquals(['ko' => '테스트 플러그인', 'en' => 'Test Plugin'], $plugin->getName());
    }

    /**
     * plugin.json에서 버전을 올바르게 파싱하는지 확인합니다.
     */
    public function test_plugin_reads_version_from_manifest(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'version' => '3.0.0',
        ]);

        $this->assertEquals('3.0.0', $plugin->getVersion());
    }

    /**
     * plugin.json에서 github_url을 올바르게 파싱하는지 확인합니다.
     */
    public function test_plugin_reads_github_url_from_manifest(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'github_url' => 'https://github.com/test/plugin',
        ]);

        $this->assertEquals('https://github.com/test/plugin', $plugin->getGithubUrl());
    }

    /**
     * plugin.json에서 g7_version을 올바르게 파싱하는지 확인합니다.
     */
    public function test_plugin_reads_required_core_version_from_manifest(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'g7_version' => '^1.0',
        ]);

        $this->assertEquals('^1.0', $plugin->getRequiredCoreVersion());
    }

    /**
     * plugin.json에서 assets를 올바르게 파싱하는지 확인합니다.
     */
    public function test_plugin_reads_assets_from_manifest(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'assets' => [
                'js' => ['entry' => 'resources/js/index.ts', 'output' => 'dist/js/plugin.iife.js'],
                'css' => ['entry' => 'resources/css/main.css', 'output' => 'dist/css/plugin.css'],
            ],
        ]);

        $assets = $plugin->getAssets();
        $this->assertEquals('dist/js/plugin.iife.js', $assets['js']['output']);
        $this->assertEquals('dist/css/plugin.css', $assets['css']['output']);
    }

    /**
     * plugin.json에서 loading 설정을 올바르게 파싱하는지 확인합니다.
     */
    public function test_plugin_reads_loading_config_from_manifest(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'loading' => [
                'strategy' => 'global',
                'priority' => 200,
                'dependencies' => ['sirsoft-ecommerce'],
            ],
        ]);

        $config = $plugin->getAssetLoadingConfig();
        $this->assertEquals('global', $config['strategy']);
        $this->assertEquals(200, $config['priority']);
        $this->assertEquals(['sirsoft-ecommerce'], $config['dependencies']);
    }

    /**
     * plugin.json이 없는 경우 폴백 값이 올바른지 확인합니다.
     */
    public function test_plugin_falls_back_when_manifest_missing(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir); // manifest 없음

        $this->assertEquals(basename($this->tempDir), $plugin->getName());
        $this->assertEquals('0.0.0', $plugin->getVersion());
        $this->assertEquals('', $plugin->getDescription());
        $this->assertNull($plugin->getGithubUrl());
        $this->assertNull($plugin->getRequiredCoreVersion());
        $this->assertEquals([], $plugin->getAssets());
    }

    /**
     * plugin.json의 loading 기본값이 올바른지 확인합니다.
     */
    public function test_plugin_loading_config_defaults(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, []);

        $config = $plugin->getAssetLoadingConfig();
        $this->assertEquals('global', $config['strategy']);
        $this->assertEquals(100, $config['priority']);
        $this->assertEquals([], $config['dependencies']);
    }

    /**
     * loadManifest()가 결과를 캐싱하는지 확인합니다.
     */
    public function test_plugin_manifest_is_cached(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, [
            'version' => '1.0.0',
        ]);

        // 첫 번째 호출
        $this->assertEquals('1.0.0', $plugin->getVersion());

        // JSON 파일 수정
        file_put_contents($this->tempDir.'/plugin.json', json_encode([
            'version' => '9.9.9',
        ]));

        // 캐시되어 있으므로 이전 값 반환
        $this->assertEquals('1.0.0', $plugin->getVersion());
    }

    /**
     * upgrades()가 빈 배열을 반환하는지 확인합니다 (upgrades/ 디렉토리 없는 경우).
     */
    public function test_plugin_upgrades_returns_empty_without_directory(): void
    {
        $plugin = $this->createTestPlugin($this->tempDir, []);

        $this->assertEquals([], $plugin->upgrades());
    }

    // ===== _bundled 디렉토리 내 실제 매니페스트 검증 =====

    /**
     * _bundled 디렉토리의 모든 module.json이 유효한 JSON인지 확인합니다.
     */
    public function test_all_bundled_module_manifests_are_valid_json(): void
    {
        $bundledDir = base_path('modules/_bundled');
        if (! is_dir($bundledDir)) {
            $this->markTestSkipped('modules/_bundled directory not found');
        }

        $dirs = array_filter(scandir($bundledDir), fn ($d) => $d !== '.' && $d !== '..');

        foreach ($dirs as $dir) {
            $manifestPath = $bundledDir.'/'.$dir.'/module.json';
            if (! file_exists($manifestPath)) {
                continue;
            }

            $content = file_get_contents($manifestPath);
            $data = json_decode($content, true);

            $this->assertNotNull($data, "module.json in {$dir} is not valid JSON");
            $this->assertArrayHasKey('identifier', $data, "module.json in {$dir} missing 'identifier'");
            $this->assertArrayHasKey('name', $data, "module.json in {$dir} missing 'name'");
            $this->assertArrayHasKey('version', $data, "module.json in {$dir} missing 'version'");
        }
    }

    /**
     * _bundled 디렉토리의 모든 plugin.json이 유효한 JSON인지 확인합니다.
     */
    public function test_all_bundled_plugin_manifests_are_valid_json(): void
    {
        $bundledDir = base_path('plugins/_bundled');
        if (! is_dir($bundledDir)) {
            $this->markTestSkipped('plugins/_bundled directory not found');
        }

        $dirs = array_filter(scandir($bundledDir), fn ($d) => $d !== '.' && $d !== '..');

        foreach ($dirs as $dir) {
            $manifestPath = $bundledDir.'/'.$dir.'/plugin.json';
            if (! file_exists($manifestPath)) {
                continue;
            }

            $content = file_get_contents($manifestPath);
            $data = json_decode($content, true);

            $this->assertNotNull($data, "plugin.json in {$dir} is not valid JSON");
            $this->assertArrayHasKey('identifier', $data, "plugin.json in {$dir} missing 'identifier'");
            $this->assertArrayHasKey('name', $data, "plugin.json in {$dir} missing 'name'");
            $this->assertArrayHasKey('version', $data, "plugin.json in {$dir} missing 'version'");
        }
    }

    /**
     * 매니페스트의 identifier가 디렉토리명과 일치하는지 확인합니다.
     */
    public function test_manifest_identifiers_match_directory_names(): void
    {
        $checks = [
            'modules/_bundled' => 'module.json',
            'plugins/_bundled' => 'plugin.json',
        ];

        foreach ($checks as $basePath => $manifestFile) {
            $fullPath = base_path($basePath);
            if (! is_dir($fullPath)) {
                continue;
            }

            $dirs = array_filter(scandir($fullPath), fn ($d) => $d !== '.' && $d !== '..');
            foreach ($dirs as $dir) {
                $manifestPath = $fullPath.'/'.$dir.'/'.$manifestFile;
                if (! file_exists($manifestPath)) {
                    continue;
                }

                $data = json_decode(file_get_contents($manifestPath), true);
                $this->assertEquals(
                    $dir,
                    $data['identifier'],
                    "{$basePath}/{$dir}/{$manifestFile}: identifier '{$data['identifier']}' does not match directory name '{$dir}'"
                );
            }
        }
    }
}
