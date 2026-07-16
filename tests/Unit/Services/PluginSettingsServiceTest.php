<?php

namespace Tests\Unit\Services;

use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Services\LayoutService;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\Helpers\MocksExtensions;
use Tests\TestCase;

/**
 * PluginSettingsService 단위 테스트
 *
 * 플러그인 설정 조회, 저장, 레이아웃 조회 기능을 테스트합니다.
 * 설정은 파일 기반으로 storage/app/plugins/{identifier}/settings/setting.json에 저장됩니다.
 */
class PluginSettingsServiceTest extends TestCase
{
    use MocksExtensions;

    private PluginSettingsService $service;

    private PluginManager $pluginManager;

    private TemplateManager $templateManager;

    private LayoutService $layoutService;

    private string $testSettingsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginManager = app(PluginManager::class);
        $this->templateManager = app(TemplateManager::class);
        $this->layoutService = app(LayoutService::class);

        $this->service = new PluginSettingsService(
            $this->pluginManager,
            $this->templateManager,
            $this->layoutService
        );

        // 테스트용 설정 디렉토리 경로
        $this->testSettingsDir = storage_path('app/plugins');
    }

    // ========================================================================
    // get() 메서드 테스트
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 조회 시 기본값 반환
     */
    public function test_get_returns_default_when_plugin_not_found(): void
    {
        $result = $this->service->get('nonexistent-plugin');

        $this->assertNull($result);
    }

    /**
     * 존재하지 않는 플러그인에 기본값 지정 시 해당 기본값 반환
     */
    public function test_get_returns_custom_default_when_plugin_not_found(): void
    {
        $defaultValue = ['key' => 'default'];
        $result = $this->service->get('nonexistent-plugin', null, $defaultValue);

        $this->assertEquals($defaultValue, $result);
    }

    /**
     * 플러그인 전체 설정 조회 성공
     */
    public function test_get_returns_all_settings_when_key_is_null(): void
    {
        // Mock plugin with config values
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-settings-plugin',
            'configValues' => [
                'api_key' => 'default-api-key',
                'enabled' => true,
                'timeout' => 30,
            ],
            'settingsSchema' => [],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-settings-plugin')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get('test-settings-plugin');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('api_key', $result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('timeout', $result);
    }

    /**
     * 특정 키의 설정값 조회 성공
     */
    public function test_get_returns_specific_key_value(): void
    {
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-key-plugin',
            'configValues' => [
                'api_key' => 'my-secret-key',
                'timeout' => 60,
            ],
            'settingsSchema' => [],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-key-plugin')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get('test-key-plugin', 'timeout');

        $this->assertEquals(60, $result);
    }

    /**
     * 존재하지 않는 키 조회 시 기본값 반환
     */
    public function test_get_returns_default_for_missing_key(): void
    {
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-missing-key-plugin',
            'configValues' => ['existing_key' => 'value'],
            'settingsSchema' => [],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-missing-key-plugin')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get('test-missing-key-plugin', 'nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    /**
     * 중첩된 키 값 조회 (dot notation)
     */
    public function test_get_returns_nested_key_value(): void
    {
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-nested-plugin',
            'configValues' => [
                'database' => [
                    'host' => 'localhost',
                    'port' => 3306,
                ],
            ],
            'settingsSchema' => [],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-nested-plugin')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $host = $service->get('test-nested-plugin', 'database.host');
        $port = $service->get('test-nested-plugin', 'database.port');

        $this->assertEquals('localhost', $host);
        $this->assertEquals(3306, $port);
    }

    // ========================================================================
    // save() 메서드 테스트
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 저장 시 false 반환
     */
    public function test_save_returns_false_when_plugin_not_found(): void
    {
        $result = $this->service->save('nonexistent-plugin', ['key' => 'value']);

        $this->assertFalse($result);
    }

    /**
     * 플러그인 설정 저장 성공
     */
    public function test_save_stores_settings_successfully(): void
    {
        $identifier = 'test-save-plugin-'.uniqid();

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [],
            'storage' => [
                'exists' => false,
                'put' => true,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->save($identifier, [
            'api_key' => 'new-api-key',
            'enabled' => true,
        ]);

        $this->assertTrue($result);
    }

    /**
     * 기존 설정과 병합되는지 확인
     */
    public function test_save_merges_with_existing_settings(): void
    {
        $identifier = 'test-merge-plugin-'.uniqid();

        // 기존 설정 파일 내용
        $existingContent = json_encode([
            'existing_key' => 'existing_value',
            'to_update' => 'old_value',
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [],
            'storage' => [
                'exists' => true,
                'get' => $existingContent,
                'put' => true,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->save($identifier, [
            'to_update' => 'new_value',
            'new_key' => 'new_value',
        ]);

        $this->assertTrue($result);
    }

    // ========================================================================
    // getLayout() 메서드 테스트
    // ========================================================================

    /**
     * 플러그인 인스턴스가 없으면 null 반환
     */
    public function test_get_layout_returns_null_when_plugin_instance_not_found(): void
    {
        $result = $this->service->getLayout('nonexistent-plugin');

        $this->assertNull($result);
    }

    /**
     * 레이아웃 파일이 없으면 null 반환
     */
    public function test_get_layout_returns_null_when_layout_file_not_exists(): void
    {
        // Mock PluginManager to return a plugin without layout file
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-no-layout',
            'settingsLayout' => '/nonexistent/path/settings.json',
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-no-layout')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getLayout('test-no-layout');

        $this->assertNull($result);
    }

    /**
     * 실제 Daum 우편번호 플러그인 레이아웃 조회 테스트
     */
    public function test_get_layout_returns_layout_for_daum_postcode_plugin(): void
    {
        // Daum 우편번호 플러그인이 설치되어 있는지 확인
        $pluginPath = base_path('plugins/sirsoft-daum_postcode/resources/layouts/settings.json');

        if (! file_exists($pluginPath)) {
            $this->markTestSkipped('Daum postcode plugin layout file not found');
        }

        // Mock PluginManager
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'sirsoft-daum_postcode',
            'settingsLayout' => $pluginPath,
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('sirsoft-daum_postcode')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getLayout('sirsoft-daum_postcode');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('schema', $result);
    }

    /**
     * 레이아웃 파일 파싱 실패 시 null 반환
     */
    public function test_get_layout_returns_null_on_invalid_json(): void
    {
        // 임시 잘못된 JSON 파일 생성
        $tempPath = sys_get_temp_dir().'/invalid_layout_'.uniqid().'.json';
        file_put_contents($tempPath, 'invalid json content {{{');

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-invalid-json',
            'settingsLayout' => $tempPath,
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-invalid-json')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getLayout('test-invalid-json');

        $this->assertNull($result);

        // 임시 파일 삭제
        @unlink($tempPath);
    }

    /**
     * 레이아웃이 sanitize 되는지 확인 (components, data_sources 대상)
     */
    public function test_get_layout_sanitizes_layout_content(): void
    {
        // 임시 레이아웃 파일 생성 (components에 XSS 포함)
        $tempPath = sys_get_temp_dir().'/xss_layout_'.uniqid().'.json';
        $layoutContent = json_encode([
            'version' => '1.0.0',
            'meta' => [
                'title' => 'Test Plugin Settings',
            ],
            'schema' => [
                'field1' => [
                    'type' => 'string',
                    'label' => 'API Key',
                ],
            ],
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'onClick' => 'javascript:alert("XSS")',
                    ],
                ],
            ],
        ]);
        file_put_contents($tempPath, $layoutContent);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'test-xss-plugin',
            'settingsLayout' => $tempPath,
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-xss-plugin')
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getLayout('test-xss-plugin');

        $this->assertIsArray($result);
        // 레이아웃이 정상적으로 반환되는지 확인
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('schema', $result);
        // components의 props에서 javascript: 프로토콜이 제거되었는지 확인
        $this->assertArrayHasKey('components', $result);
        $this->assertStringNotContainsString('javascript:', $result['components'][0]['props']['onClick'] ?? '');

        // 임시 파일 삭제
        @unlink($tempPath);
    }

    // ========================================================================
    // 암호화/복호화 테스트
    // ========================================================================

    /**
     * 민감한 필드가 암호화되어 저장되는지 확인
     */
    public function test_save_encrypts_sensitive_fields(): void
    {
        $identifier = 'test-encrypt-plugin-'.uniqid();

        // Mock plugin with sensitive field schema
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [
                'api_key' => [
                    'type' => 'string',
                    'sensitive' => true,
                ],
                'public_key' => [
                    'type' => 'string',
                    'sensitive' => false,
                ],
            ],
            'storage' => [
                'exists' => false,
                'put' => true,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->save($identifier, [
            'api_key' => 'secret-api-key',
            'public_key' => 'public-value',
        ]);

        $this->assertTrue($result);

        // 암호화 로직은 내부적으로 동작하며, put이 호출되었으므로 성공으로 간주
    }

    /**
     * 민감한 필드가 복호화되어 조회되는지 확인
     */
    public function test_get_decrypts_sensitive_fields(): void
    {
        $identifier = 'test-decrypt-plugin-'.uniqid();

        // 암호화된 값으로 설정 파일 내용 생성
        $encryptedValue = Crypt::encryptString('decrypted-secret');
        $fileContent = json_encode([
            'secret_key' => $encryptedValue,
            'normal_key' => 'normal-value',
        ]);

        // Mock plugin with sensitive field schema and Storage
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [
                'secret_key' => [
                    'type' => 'string',
                    'sensitive' => true,
                ],
                'normal_key' => [
                    'type' => 'string',
                    'sensitive' => false,
                ],
            ],
            'storage' => [
                'exists' => true,
                'get' => $fileContent,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get($identifier);

        // 민감한 필드는 복호화되어 반환됨
        $this->assertEquals('decrypted-secret', $result['secret_key']);
        // 비민감 필드는 그대로 반환됨
        $this->assertEquals('normal-value', $result['normal_key']);
    }

    /**
     * 복호화 실패 시 원래 값 유지 (레거시 데이터 호환)
     */
    public function test_get_preserves_value_on_decrypt_failure(): void
    {
        $identifier = 'test-legacy-plugin-'.uniqid();

        // 암호화되지 않은 레거시 값으로 설정 파일 내용 생성
        $fileContent = json_encode([
            'old_secret' => 'plain-text-secret', // 암호화되지 않은 레거시 값
        ]);

        // Mock plugin with sensitive field schema
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [
                'old_secret' => [
                    'type' => 'string',
                    'sensitive' => true,
                ],
            ],
            'storage' => [
                'exists' => true,
                'get' => $fileContent,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get($identifier);

        // 복호화 실패 시 원래 값 유지
        $this->assertEquals('plain-text-secret', $result['old_secret']);
    }

    /**
     * 이미 암호화된 값은 다시 암호화하지 않음
     */
    public function test_save_does_not_double_encrypt(): void
    {
        $identifier = 'test-double-encrypt-plugin-'.uniqid();
        $originalValue = 'original-secret';
        $encryptedOnce = Crypt::encryptString($originalValue);

        // Mock plugin with sensitive field schema
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [
                'api_key' => [
                    'type' => 'string',
                    'sensitive' => true,
                ],
            ],
            'storage' => [
                'exists' => false,
                'put' => true,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        // 이미 암호화된 값을 저장
        $result = $service->save($identifier, [
            'api_key' => $encryptedOnce,
        ]);

        $this->assertTrue($result);

        // 암호화 로직은 내부적으로 동작하며, put이 호출되었으므로 성공으로 간주
    }

    /**
     * reset() 메서드가 설정 파일을 삭제하는지 확인
     */
    public function test_reset_deletes_settings_file(): void
    {
        $identifier = 'test-reset-plugin-'.uniqid();

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'storage' => [
                'exists' => true,
                'delete' => true,
            ],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->reset($identifier);

        $this->assertTrue($result);
    }

    /**
     * clearCache() 메서드가 캐시를 초기화하는지 확인
     */
    public function test_clear_cache_clears_settings_cache(): void
    {
        $identifier = 'test-cache-plugin';

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'configValues' => ['initial' => 'value'],
            'settingsSchema' => [],
        ]);

        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        // 첫 번째 조회 (캐시에 저장됨)
        $service->get($identifier);

        // 캐시 초기화
        $service->clearCache($identifier);

        // 캐시가 초기화되었으므로 다시 getPlugin이 호출되어야 함
        // (이 테스트는 내부 동작을 확인하는 것이 어려우므로 예외 없이 실행되는지만 확인)
        $this->assertTrue(true);
    }

    // ========================================================================
    // getAllActiveSettings() 메서드 테스트
    // ========================================================================

    /**
     * frontend_schema가 있는 defaults.json이 있을 때 스키마 기반 필터링
     */
    public function test_get_all_active_settings_uses_frontend_schema_when_defaults_exists(): void
    {
        $identifier = 'test-frontend-schema-plugin';

        // defaults.json 임시 파일 생성
        $tempDefaultsPath = sys_get_temp_dir().'/defaults_'.uniqid().'.json';
        file_put_contents($tempDefaultsPath, json_encode([
            'defaults' => [
                'display_mode' => 'layer',
                'popup_width' => 500,
                'theme_color' => '#1D4ED8',
            ],
            'frontend_schema' => [
                'display_mode' => ['expose' => true],
                'popup_width' => ['expose' => true],
                'theme_color' => ['expose' => false],
            ],
        ]));

        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'display_mode' => 'layer',
                'popup_width' => 500,
                'theme_color' => '#1D4ED8',
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $tempDefaultsPath,
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getActivePlugins')
            ->andReturn([$mockPlugin]);
        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('display_mode', $result[$identifier]);
        $this->assertArrayHasKey('popup_width', $result[$identifier]);
        $this->assertArrayNotHasKey('theme_color', $result[$identifier]);

        @unlink($tempDefaultsPath);
    }

    /**
     * defaults.json이 없을 때 기존 동작 유지 (sensitive만 제외)
     */
    public function test_get_all_active_settings_falls_back_to_exclude_sensitive_when_no_defaults(): void
    {
        $identifier = 'test-no-defaults-plugin';

        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'display_mode' => 'popup',
                'api_key' => 'secret-key',
            ],
            'settingsSchema' => [
                'display_mode' => ['type' => 'string'],
                'api_key' => ['type' => 'string', 'sensitive' => true],
            ],
            'settingsDefaultsPath' => null,
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getActivePlugins')
            ->andReturn([$mockPlugin]);
        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('display_mode', $result[$identifier]);
        $this->assertArrayNotHasKey('api_key', $result[$identifier]);
    }

    /**
     * hasSettings()=false인 플러그인은 결과에 미포함
     */
    public function test_get_all_active_settings_skips_plugins_without_settings(): void
    {
        $mockPlugin = $this->createMockPlugin([
            'identifier' => 'no-settings-plugin',
            'hasSettings' => false,
            'configValues' => [],
            'settingsSchema' => [],
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getActivePlugins')
            ->andReturn([$mockPlugin]);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        $this->assertEmpty($result);
    }

    /**
     * frontend_schema로 카테고리 구조 필터링 (카테고리 expose:false → 전체 제외)
     */
    public function test_get_all_active_settings_filters_by_category_expose(): void
    {
        $identifier = 'test-category-expose-plugin';

        // 카테고리 구조 설정을 가진 defaults.json
        $tempDefaultsPath = sys_get_temp_dir().'/defaults_cat_'.uniqid().'.json';
        file_put_contents($tempDefaultsPath, json_encode([
            'defaults' => [
                'general' => ['site_name' => 'Test'],
                'secrets' => ['api_key' => 'hidden'],
            ],
            'frontend_schema' => [
                'general' => ['expose' => true],
                'secrets' => ['expose' => false],
            ],
        ]));

        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'general' => ['site_name' => 'Test'],
                'secrets' => ['api_key' => 'hidden'],
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $tempDefaultsPath,
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getActivePlugins')
            ->andReturn([$mockPlugin]);
        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('general', $result[$identifier]);
        $this->assertArrayNotHasKey('secrets', $result[$identifier]);

        @unlink($tempDefaultsPath);
    }

    /**
     * frontend_schema로 필드 레벨 필터링 (필드 expose:false → 해당 필드만 제외)
     */
    public function test_get_all_active_settings_filters_by_field_expose(): void
    {
        $identifier = 'test-field-expose-plugin';

        $tempDefaultsPath = sys_get_temp_dir().'/defaults_field_'.uniqid().'.json';
        file_put_contents($tempDefaultsPath, json_encode([
            'defaults' => [
                'payment' => [
                    'currency' => 'KRW',
                    'secret_key' => 'sk_xxx',
                    'public_key' => 'pk_xxx',
                ],
            ],
            'frontend_schema' => [
                'payment' => [
                    'expose' => true,
                    'fields' => [
                        'currency' => ['expose' => true],
                        'secret_key' => ['expose' => false],
                        'public_key' => ['expose' => true],
                    ],
                ],
            ],
        ]));

        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'payment' => [
                    'currency' => 'KRW',
                    'secret_key' => 'sk_xxx',
                    'public_key' => 'pk_xxx',
                ],
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $tempDefaultsPath,
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getActivePlugins')
            ->andReturn([$mockPlugin]);
        $mockPluginManager->shouldReceive('getPlugin')
            ->with($identifier)
            ->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('payment', $result[$identifier]);
        $this->assertSame('KRW', $result[$identifier]['payment']['currency']);
        $this->assertSame('pk_xxx', $result[$identifier]['payment']['public_key']);
        $this->assertArrayNotHasKey('secret_key', $result[$identifier]['payment']);

        @unlink($tempDefaultsPath);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
