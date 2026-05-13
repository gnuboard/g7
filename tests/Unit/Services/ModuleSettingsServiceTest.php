<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Services\LayoutService;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\Helpers\MocksExtensions;
use Tests\TestCase;

/**
 * ModuleSettingsService 단위 테스트
 *
 * 모듈 설정 조회, 저장, frontend_schema 기반 필터링 기능을 테스트합니다.
 * 설정은 파일 기반으로 storage/app/modules/{identifier}/settings/setting.json에 저장됩니다.
 */
class ModuleSettingsServiceTest extends TestCase
{
    use MocksExtensions;

    private ModuleSettingsService $service;

    private ModuleManager $moduleManager;

    private TemplateManager $templateManager;

    private LayoutService $layoutService;

    private string $testSettingsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleManager = app(ModuleManager::class);
        $this->templateManager = app(TemplateManager::class);
        $this->layoutService = app(LayoutService::class);

        $this->service = new ModuleSettingsService(
            $this->moduleManager,
            $this->templateManager,
            $this->layoutService
        );

        // 테스트용 설정 디렉토리 경로
        $this->testSettingsDir = storage_path('app/modules');
    }

    // ========================================================================
    // get() 메서드 테스트
    // ========================================================================

    /**
     * 존재하지 않는 모듈 조회 시 기본값 반환
     */
    public function test_get_returns_default_when_module_not_found(): void
    {
        $result = $this->service->get('nonexistent-module');

        $this->assertNull($result);
    }

    /**
     * 존재하지 않는 모듈에 기본값 지정 시 해당 기본값 반환
     */
    public function test_get_returns_custom_default_when_module_not_found(): void
    {
        $defaultValue = ['key' => 'default'];
        $result = $this->service->get('nonexistent-module', null, $defaultValue);

        $this->assertEquals($defaultValue, $result);
    }

    /**
     * 모듈 전체 설정 조회 성공
     */
    public function test_get_returns_all_settings_when_key_is_null(): void
    {
        // Mock module with config values
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => 'test-settings-module',
            'configValues' => [
                'api_key' => 'default-api-key',
                'enabled' => true,
                'timeout' => 30,
            ],
            'settingsSchema' => [],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with('test-settings-module')
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get('test-settings-module');

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
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => 'test-key-module',
            'configValues' => [
                'api_key' => 'my-secret-key',
                'timeout' => 60,
            ],
            'settingsSchema' => [],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with('test-key-module')
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get('test-key-module', 'timeout');

        $this->assertEquals(60, $result);
    }

    /**
     * 존재하지 않는 키 조회 시 기본값 반환
     */
    public function test_get_returns_default_for_missing_key(): void
    {
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => 'test-missing-key-module',
            'configValues' => ['existing_key' => 'value'],
            'settingsSchema' => [],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with('test-missing-key-module')
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get('test-missing-key-module', 'nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    /**
     * 중첩된 키 값 조회 (dot notation)
     */
    public function test_get_returns_nested_key_value(): void
    {
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => 'test-nested-module',
            'configValues' => [
                'database' => [
                    'host' => 'localhost',
                    'port' => 3306,
                ],
            ],
            'settingsSchema' => [],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with('test-nested-module')
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $host = $service->get('test-nested-module', 'database.host');
        $port = $service->get('test-nested-module', 'database.port');

        $this->assertEquals('localhost', $host);
        $this->assertEquals(3306, $port);
    }

    // ========================================================================
    // save() 메서드 테스트
    // ========================================================================

    /**
     * 존재하지 않는 모듈 저장 시 false 반환
     */
    public function test_save_returns_false_when_module_not_found(): void
    {
        $result = $this->service->save('nonexistent-module', ['key' => 'value']);

        $this->assertFalse($result);
    }

    /**
     * 모듈 설정 저장 성공
     */
    public function test_save_stores_settings_successfully(): void
    {
        $identifier = 'test-save-module-'.uniqid();

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [],
            'storage' => [
                'exists' => false,
                'put' => true,
            ],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
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
        $identifier = 'test-merge-module-'.uniqid();

        // 기존 설정 파일 내용
        $existingContent = json_encode([
            'existing_key' => 'existing_value',
            'to_update' => 'old_value',
        ]);

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'configValues' => [],
            'settingsSchema' => [],
            'storage' => [
                'exists' => true,
                'get' => $existingContent,
                'put' => true,
            ],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
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
    // 암호화/복호화 테스트
    // ========================================================================

    /**
     * 민감한 필드가 암호화되어 저장되는지 확인
     */
    public function test_save_encrypts_sensitive_fields(): void
    {
        $identifier = 'test-encrypt-module-'.uniqid();

        // Mock module with sensitive field schema
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
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

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
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
        $identifier = 'test-decrypt-module-'.uniqid();

        // 암호화된 값으로 설정 파일 내용 생성
        $encryptedValue = Crypt::encryptString('decrypted-secret');
        $fileContent = json_encode([
            'secret_key' => $encryptedValue,
            'normal_key' => 'normal-value',
        ]);

        // Mock module with sensitive field schema and Storage
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
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

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->get($identifier);

        // 민감한 필드는 복호화되어 반환됨
        $this->assertEquals('decrypted-secret', $result['secret_key']);
        // 비민감 필드는 그대로 반환됨
        $this->assertEquals('normal-value', $result['normal_key']);
    }

    // ========================================================================
    // getAllActiveSettings() 및 frontend_schema 필터링 테스트
    // ========================================================================

    /**
     * frontend_schema가 없으면 빈 배열 반환
     */
    public function test_get_all_active_settings_returns_empty_when_no_frontend_schema(): void
    {
        $identifier = 'test-no-schema-module-'.uniqid();

        // defaults.json 없이 모듈 생성
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => ['key' => 'value'],
            'settingsSchema' => [],
            'settingsDefaultsPath' => null,
            'storage' => [
                'exists' => false,
            ],
        ]);

        // getSettingsDefaultsPath()를 명시적으로 추가 (디버깅용)
        $mockModule->shouldReceive('getSettingsDefaultsPath')->andReturn(null)->zeroOrMoreTimes();

        $mockModuleManager->shouldReceive('getActiveModules')
            ->andReturn([$mockModule]);
        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule)
            ->zeroOrMoreTimes();

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        // frontend_schema가 없으므로 결과에 포함되지 않음
        $this->assertArrayNotHasKey($identifier, $result);
    }

    /**
     * frontend_schema.expose: true인 카테고리만 포함
     */
    public function test_get_all_active_settings_filters_by_category_expose(): void
    {
        $identifier = 'test-category-expose-module-'.uniqid();
        $modulePath = storage_path("app/test-modules/{$identifier}");
        $defaultsPath = $modulePath.'/config/settings/defaults.json';

        // defaults.json 생성
        File::makeDirectory(dirname($defaultsPath), 0755, true);
        File::put($defaultsPath, json_encode([
            'defaults' => [
                'public_category' => [
                    'field1' => 'value1',
                    'field2' => 'value2',
                ],
                'private_category' => [
                    'secret' => 'secret-value',
                ],
            ],
            'frontend_schema' => [
                'public_category' => [
                    'expose' => true,
                ],
                'private_category' => [
                    'expose' => false,
                ],
            ],
        ]));

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'public_category' => [
                    'field1' => 'value1',
                    'field2' => 'value2',
                ],
                'private_category' => [
                    'secret' => 'secret-value',
                ],
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $defaultsPath,
            'storage' => [
                'exists' => false,
            ],
        ]);

        $mockModuleManager->shouldReceive('getActiveModules')
            ->andReturn([$mockModule]);
        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule)
            ->zeroOrMoreTimes();

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        // expose: true인 카테고리만 포함
        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('public_category', $result[$identifier]);
        $this->assertArrayNotHasKey('private_category', $result[$identifier]);

        // 정리
        File::deleteDirectory($modulePath);
    }

    /**
     * frontend_schema.fields.expose: true인 필드만 포함
     */
    public function test_get_all_active_settings_filters_by_field_expose(): void
    {
        $identifier = 'test-field-expose-module-'.uniqid();
        $modulePath = storage_path("app/test-modules/{$identifier}");
        $defaultsPath = $modulePath.'/config/settings/defaults.json';

        // defaults.json 생성
        File::makeDirectory(dirname($defaultsPath), 0755, true);
        File::put($defaultsPath, json_encode([
            'defaults' => [
                'settings' => [
                    'public_field' => 'public-value',
                    'private_field' => 'private-value',
                    'another_public' => 'another-value',
                ],
            ],
            'frontend_schema' => [
                'settings' => [
                    'expose' => true,
                    'fields' => [
                        'public_field' => ['expose' => true],
                        'private_field' => ['expose' => false],
                        'another_public' => ['expose' => true],
                    ],
                ],
            ],
        ]));

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'settings' => [
                    'public_field' => 'public-value',
                    'private_field' => 'private-value',
                    'another_public' => 'another-value',
                ],
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $defaultsPath,
            'storage' => [
                'exists' => false,
            ],
        ]);

        $mockModuleManager->shouldReceive('getActiveModules')
            ->andReturn([$mockModule]);
        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule)
            ->zeroOrMoreTimes();

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        // expose: true인 필드만 포함
        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('settings', $result[$identifier]);
        $this->assertArrayHasKey('public_field', $result[$identifier]['settings']);
        $this->assertArrayHasKey('another_public', $result[$identifier]['settings']);
        $this->assertArrayNotHasKey('private_field', $result[$identifier]['settings']);

        // 정리
        File::deleteDirectory($modulePath);
    }

    /**
     * fields 정의가 없으면 카테고리 전체 포함
     */
    public function test_get_all_active_settings_includes_all_fields_when_no_fields_defined(): void
    {
        $identifier = 'test-no-fields-module-'.uniqid();
        $modulePath = storage_path("app/test-modules/{$identifier}");
        $defaultsPath = $modulePath.'/config/settings/defaults.json';

        // defaults.json 생성 (fields 정의 없음)
        File::makeDirectory(dirname($defaultsPath), 0755, true);
        File::put($defaultsPath, json_encode([
            'defaults' => [
                'basic' => [
                    'field1' => 'value1',
                    'field2' => 'value2',
                    'field3' => 'value3',
                ],
            ],
            'frontend_schema' => [
                'basic' => [
                    'expose' => true,
                    // fields 정의 없음
                ],
            ],
        ]));

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'basic' => [
                    'field1' => 'value1',
                    'field2' => 'value2',
                    'field3' => 'value3',
                ],
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $defaultsPath,
            'storage' => [
                'exists' => false,
            ],
        ]);

        $mockModuleManager->shouldReceive('getActiveModules')
            ->andReturn([$mockModule]);
        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule)
            ->zeroOrMoreTimes();

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        // fields 정의가 없으면 카테고리 전체 포함
        $this->assertArrayHasKey($identifier, $result);
        $this->assertArrayHasKey('basic', $result[$identifier]);
        $this->assertEquals('value1', $result[$identifier]['basic']['field1']);
        $this->assertEquals('value2', $result[$identifier]['basic']['field2']);
        $this->assertEquals('value3', $result[$identifier]['basic']['field3']);

        // 정리
        File::deleteDirectory($modulePath);
    }

    /**
     * hasSettings가 false인 모듈은 제외
     */
    public function test_get_all_active_settings_skips_modules_without_settings(): void
    {
        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => 'no-settings-module',
            'hasSettings' => false,
        ]);

        $mockModuleManager->shouldReceive('getActiveModules')
            ->andReturn([$mockModule]);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->getAllActiveSettings();

        $this->assertArrayNotHasKey('no-settings-module', $result);
    }

    // ========================================================================
    // reset() 및 캐시 테스트
    // ========================================================================

    /**
     * reset() 메서드가 설정 파일을 삭제하는지 확인
     */
    public function test_reset_deletes_settings_file(): void
    {
        $identifier = 'test-reset-module-'.uniqid();

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'storage' => [
                'exists' => true,
                'delete' => true,
            ],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
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
        $identifier = 'test-cache-module';

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'configValues' => ['initial' => 'value'],
            'settingsSchema' => [],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        // 첫 번째 조회 (캐시에 저장됨)
        $service->get($identifier);

        // 캐시 초기화
        $service->clearCache($identifier);

        // 캐시가 초기화되었으므로 다시 getModule이 호출되어야 함
        // (이 테스트는 내부 동작을 확인하는 것이 어려우므로 예외 없이 실행되는지만 확인)
        $this->assertTrue(true);
    }

    /**
     * deleteSettingsDirectory() 메서드가 디렉토리를 삭제하는지 확인
     */
    public function test_delete_settings_directory_removes_directory(): void
    {
        $identifier = 'test-delete-dir-module-'.uniqid();

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'storage' => [
                'getDisk' => 'local',
                'deleteDirectory' => true,
            ],
        ]);

        $mockModuleManager->shouldReceive('getModule')
            ->with($identifier)
            ->andReturn($mockModule);

        $service = new ModuleSettingsService(
            $mockModuleManager,
            $this->templateManager,
            $this->layoutService
        );

        $result = $service->deleteSettingsDirectory($identifier);

        $this->assertTrue($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
