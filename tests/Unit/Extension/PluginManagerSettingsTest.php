<?php

namespace Tests\Unit\Extension;

use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Helpers\MocksExtensions;
use Tests\TestCase;

/**
 * PluginManager 설정 초기화 테스트
 *
 * initializePluginSettings()의 defaults.json 우선순위 로직을 테스트합니다.
 */
class PluginManagerSettingsTest extends TestCase
{
    use MocksExtensions;

    private string $testSettingsDir;

    private string $testDefaultsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSettingsDir = storage_path('app/plugins/test-init-plugin/settings');
        $this->testDefaultsDir = sys_get_temp_dir().'/g7_test_defaults_'.uniqid();

        // 테스트용 디렉토리 정리
        if (File::isDirectory($this->testSettingsDir)) {
            File::deleteDirectory($this->testSettingsDir);
        }

        if (! File::isDirectory($this->testDefaultsDir)) {
            File::makeDirectory($this->testDefaultsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 테스트 후 정리
        if (File::isDirectory($this->testSettingsDir)) {
            File::deleteDirectory($this->testSettingsDir);
        }

        $parentDir = dirname($this->testSettingsDir);
        if (File::isDirectory($parentDir) && empty(File::directories($parentDir)) && empty(File::files($parentDir))) {
            File::deleteDirectory($parentDir);
        }

        if (File::isDirectory($this->testDefaultsDir)) {
            File::deleteDirectory($this->testDefaultsDir);
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * defaults.json이 있으면 defaults 섹션으로 setting.json 초기화
     */
    public function test_initialize_plugin_settings_uses_defaults_json_when_available(): void
    {
        // defaults.json 생성
        $defaultsPath = $this->testDefaultsDir.'/defaults.json';
        File::put($defaultsPath, json_encode([
            'defaults' => [
                'display_mode' => 'layer',
                'popup_width' => 500,
            ],
            'frontend_schema' => [
                'display_mode' => ['expose' => true],
            ],
        ]));

        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-init-plugin',
            'configValues' => [
                'display_mode' => 'popup',
                'popup_width' => 400,
            ],
            'settingsDefaultsPath' => $defaultsPath,
        ]);

        // PluginManager의 protected 메서드 호출을 위해 리플렉션 사용
        $pluginManager = app(PluginManager::class);
        $method = new \ReflectionMethod($pluginManager, 'initializePluginSettings');
        $method->setAccessible(true);

        $method->invoke($pluginManager, $mockPlugin);

        // setting.json이 defaults.json의 defaults 섹션 값으로 생성되었는지 확인
        $settingsPath = $this->testSettingsDir.'/setting.json';
        $this->assertFileExists($settingsPath);

        $savedSettings = json_decode(File::get($settingsPath), true);
        $this->assertSame('layer', $savedSettings['display_mode']);
        $this->assertSame(500, $savedSettings['popup_width']);
    }

    /**
     * defaults.json이 없으면 getConfigValues()로 폴백 (하위 호환성)
     */
    public function test_initialize_plugin_settings_falls_back_to_get_config_values(): void
    {
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-init-plugin',
            'configValues' => [
                'api_key' => 'default-key',
                'enabled' => true,
            ],
            'settingsDefaultsPath' => null,
        ]);

        $pluginManager = app(PluginManager::class);
        $method = new \ReflectionMethod($pluginManager, 'initializePluginSettings');
        $method->setAccessible(true);

        $method->invoke($pluginManager, $mockPlugin);

        // setting.json이 getConfigValues() 값으로 생성되었는지 확인
        $settingsPath = $this->testSettingsDir.'/setting.json';
        $this->assertFileExists($settingsPath);

        $savedSettings = json_decode(File::get($settingsPath), true);
        $this->assertSame('default-key', $savedSettings['api_key']);
        $this->assertTrue($savedSettings['enabled']);
    }

    /**
     * 이미 setting.json이 존재하면 덮어쓰기 방지 (재설치 시 기존 설정 유지)
     */
    public function test_initialize_plugin_settings_skips_when_settings_file_exists(): void
    {
        // 기존 설정 파일 생성
        if (! File::isDirectory($this->testSettingsDir)) {
            File::makeDirectory($this->testSettingsDir, 0755, true);
        }

        $existingSettings = ['display_mode' => 'custom', 'custom_value' => 'preserve'];
        File::put(
            $this->testSettingsDir.'/setting.json',
            json_encode($existingSettings)
        );

        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-init-plugin',
            'configValues' => [
                'display_mode' => 'new_value',
            ],
            'settingsDefaultsPath' => null,
        ]);

        $pluginManager = app(PluginManager::class);
        $method = new \ReflectionMethod($pluginManager, 'initializePluginSettings');
        $method->setAccessible(true);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '이미 존재');
            });

        $method->invoke($pluginManager, $mockPlugin);

        // 기존 설정이 유지되는지 확인
        $settingsPath = $this->testSettingsDir.'/setting.json';
        $savedSettings = json_decode(File::get($settingsPath), true);
        $this->assertSame('custom', $savedSettings['display_mode']);
        $this->assertSame('preserve', $savedSettings['custom_value']);
    }
}
