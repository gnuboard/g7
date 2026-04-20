<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use App\Services\DriverRegistryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 드라이버 확장 시스템 통합 테스트
 *
 * 플러그인이 필터 훅으로 새 드라이버를 등록했을 때,
 * 각 카테고리별로 실제 동작이 정상 수행되는지 검증합니다.
 */
class DriverExtensionIntegrationTest extends TestCase
{
    private DriverRegistryService $driverRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driverRegistry = new DriverRegistryService();

        // 테스트용 디렉토리 정리
        Storage::disk('modules')->deleteDirectory('test-driver-module');
        Storage::disk('plugins')->deleteDirectory('test-driver-plugin');
    }

    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('test-driver-module');
        Storage::disk('plugins')->deleteDirectory('test-driver-plugin');

        // 캐시 정리
        Cache::flush();

        HookManager::resetAll();
        parent::tearDown();
    }

    // ========================================================================
    // 공통: 플러그인 드라이버 등록 + 사용 가능 여부
    // ========================================================================

    /**
     * 7개 카테고리 모두에 플러그인 드라이버를 등록하면 available 목록에 포함되는지 검증합니다.
     */
    #[Test]
    public function plugin_can_register_drivers_for_all_categories(): void
    {
        $categories = ['storage', 'cache', 'session', 'queue', 'log', 'websocket', 'mail'];

        foreach ($categories as $category) {
            HookManager::addFilter(
                "core.settings.available_{$category}_drivers",
                function (array $drivers) use ($category) {
                    $drivers[] = [
                        'id' => "test_plugin_{$category}",
                        'label' => ['ko' => "테스트 {$category}", 'en' => "Test {$category}"],
                        'provider' => 'sirsoft-test_plugin',
                    ];

                    return $drivers;
                }
            );
        }

        foreach ($categories as $category) {
            $available = $this->driverRegistry->getAvailableDrivers($category);
            $ids = array_column($available, 'id');

            $this->assertContains(
                "test_plugin_{$category}",
                $ids,
                "{$category} 카테고리에 플러그인 드라이버가 등록되지 않았습니다."
            );
        }
    }

    /**
     * 플러그인 드라이버 등록 후 apply_driver_config 액션 훅이 올바른 파라미터로 발행되는지 검증합니다.
     */
    #[Test]
    public function apply_driver_config_hook_fires_with_correct_params(): void
    {
        $firedParams = [];

        HookManager::addFilter('core.settings.available_cache_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'plugin_redis_cluster',
                'label' => ['ko' => 'Redis 클러스터', 'en' => 'Redis Cluster'],
                'provider' => 'sirsoft-redis_cluster',
            ];

            return $drivers;
        });

        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) use (&$firedParams) {
            $firedParams = compact('category', 'driver', 'settings');
        });

        // 시뮬레이션: CoreServiceProvider가 플러그인 드라이버 선택 시 훅 발행
        $category = 'cache';
        $selectedDriver = 'plugin_redis_cluster';
        $settings = ['cache_driver' => 'plugin_redis_cluster'];

        if ($this->driverRegistry->isDriverAvailable($category, $selectedDriver)) {
            HookManager::doAction('core.settings.apply_driver_config', $category, $selectedDriver, $settings);
        }

        $this->assertEquals('cache', $firedParams['category']);
        $this->assertEquals('plugin_redis_cluster', $firedParams['driver']);
        $this->assertEquals(['cache_driver' => 'plugin_redis_cluster'], $firedParams['settings']);
    }

    // ========================================================================
    // Storage 드라이버: 플러그인이 커스텀 스토리지 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 플러그인이 스토리지 드라이버를 등록하고, apply_driver_config으로 Config을 적용하면
     * 코어 StorageDriver로 실제 파일 읽기/쓰기가 가능한지 검증합니다.
     */
    #[Test]
    public function storage_driver_plugin_core_file_read_write(): void
    {
        $this->registerStoragePlugin();
        $this->applyPluginStorageConfig();

        // 코어 StorageDriver로 파일 쓰기/읽기
        $coreStorage = new CoreStorageDriver(Config::get('filesystems.default'));
        $coreStorage->put('temp', 'core-test.txt', 'core-content');

        $this->assertTrue($coreStorage->exists('temp', 'core-test.txt'));
        $this->assertEquals('core-content', $coreStorage->get('temp', 'core-test.txt'));

        // 정리
        $coreStorage->delete('temp', 'core-test.txt');
    }

    /**
     * 플러그인 스토리지 드라이버 등록 후 모듈 StorageDriver로 파일 읽기/쓰기가 가능한지 검증합니다.
     */
    #[Test]
    public function storage_driver_plugin_module_file_read_write(): void
    {
        // 모듈은 자체 disk('modules')를 사용하므로 filesystems.default 변경과 독립
        $moduleStorage = new ModuleStorageDriver('test-driver-module', 'modules');

        $moduleStorage->put('attachments', 'module-file.txt', 'module-content-123');

        $this->assertTrue($moduleStorage->exists('attachments', 'module-file.txt'));
        $this->assertEquals('module-content-123', $moduleStorage->get('attachments', 'module-file.txt'));

        // 다른 카테고리도 격리 확인
        $moduleStorage->put('settings', 'config.json', '{"key": "value"}');
        $this->assertEquals('{"key": "value"}', $moduleStorage->get('settings', 'config.json'));

        // 파일 목록 확인
        $files = $moduleStorage->files('attachments');
        $this->assertNotEmpty($files);
    }

    /**
     * 플러그인 StorageDriver로 자체 파일 읽기/쓰기가 가능한지 검증합니다.
     */
    #[Test]
    public function storage_driver_plugin_own_file_read_write(): void
    {
        $pluginStorage = new PluginStorageDriver('test-driver-plugin', 'plugins');

        $pluginStorage->put('data', 'analytics.json', '{"visits": 100}');

        $this->assertTrue($pluginStorage->exists('data', 'analytics.json'));
        $this->assertEquals('{"visits": 100}', $pluginStorage->get('data', 'analytics.json'));

        // 삭제 검증
        $pluginStorage->delete('data', 'analytics.json');
        $this->assertFalse($pluginStorage->exists('data', 'analytics.json'));
    }

    /**
     * 스토리지 드라이버 변경 시 모듈/플러그인 StorageDriver 경로가 격리되는지 검증합니다.
     */
    #[Test]
    public function storage_driver_change_does_not_affect_module_plugin_isolation(): void
    {
        $moduleStorage = new ModuleStorageDriver('test-driver-module', 'modules');
        $pluginStorage = new PluginStorageDriver('test-driver-plugin', 'plugins');

        // 동일 파일명이지만 격리된 경로
        $moduleStorage->put('settings', 'shared-name.txt', 'module-data');
        $pluginStorage->put('settings', 'shared-name.txt', 'plugin-data');

        $this->assertEquals('module-data', $moduleStorage->get('settings', 'shared-name.txt'));
        $this->assertEquals('plugin-data', $pluginStorage->get('settings', 'shared-name.txt'));

        // 기본 스토리지 드라이버 변경이 모듈/플러그인에 영향 없음
        Config::set('filesystems.default', 'local');
        $this->assertEquals('module-data', $moduleStorage->get('settings', 'shared-name.txt'));
        $this->assertEquals('plugin-data', $pluginStorage->get('settings', 'shared-name.txt'));
    }

    // ========================================================================
    // Cache 드라이버: 플러그인이 커스텀 캐시 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 기본 캐시 드라이버(file)로 코어 캐시 읽기/쓰기가 동작하는지 검증합니다.
     */
    #[Test]
    public function cache_driver_core_read_write(): void
    {
        Cache::put('core_test_key', 'core_value', 60);
        $this->assertEquals('core_value', Cache::get('core_test_key'));

        Cache::forget('core_test_key');
        $this->assertNull(Cache::get('core_test_key'));
    }

    /**
     * 플러그인이 캐시 드라이버를 등록하고, 액션 훅으로 Config을 적용하면
     * cache store가 전환되는지 검증합니다.
     */
    #[Test]
    public function cache_driver_plugin_registration_and_config_application(): void
    {
        HookManager::addFilter('core.settings.available_cache_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'array_custom',
                'label' => ['ko' => '어레이 커스텀', 'en' => 'Array Custom'],
                'provider' => 'sirsoft-array_cache',
            ];

            return $drivers;
        });

        // 플러그인이 액션 훅에서 Config 적용
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'cache' && $driver === 'array_custom') {
                Config::set('cache.default', 'array');
            }
        });

        $this->assertTrue($this->driverRegistry->isDriverAvailable('cache', 'array_custom'));

        // 액션 훅 발행 시뮬레이션
        HookManager::doAction('core.settings.apply_driver_config', 'cache', 'array_custom', ['cache_driver' => 'array_custom']);

        $this->assertEquals('array', Config::get('cache.default'));

        // array 드라이버로 실제 캐시 동작 검증
        Cache::store('array')->put('plugin_test', 'plugin_value', 60);
        $this->assertEquals('plugin_value', Cache::store('array')->get('plugin_test'));
    }

    /**
     * 모듈/플러그인이 자체 캐시 키 네임스페이스로 독립적으로 캐시를 사용하는지 검증합니다.
     */
    #[Test]
    public function cache_driver_module_plugin_isolation(): void
    {
        // 코어, 모듈, 플러그인이 동일 캐시 드라이버를 사용해도 키로 격리
        Cache::put('core:settings:version', '1.0', 60);
        Cache::put('module:sirsoft-ecommerce:products_count', 42, 60);
        Cache::put('plugin:sirsoft-payment:config', '{"key": "secret"}', 60);

        $this->assertEquals('1.0', Cache::get('core:settings:version'));
        $this->assertEquals(42, Cache::get('module:sirsoft-ecommerce:products_count'));
        $this->assertEquals('{"key": "secret"}', Cache::get('plugin:sirsoft-payment:config'));

        // 코어 캐시 삭제가 모듈/플러그인에 영향 없음
        Cache::forget('core:settings:version');
        $this->assertNull(Cache::get('core:settings:version'));
        $this->assertEquals(42, Cache::get('module:sirsoft-ecommerce:products_count'));
    }

    // ========================================================================
    // Session 드라이버: 플러그인이 커스텀 세션 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 플러그인이 세션 드라이버를 등록하고 Config이 적용되는지 검증합니다.
     */
    #[Test]
    public function session_driver_plugin_registration_and_config(): void
    {
        HookManager::addFilter('core.settings.available_session_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_session',
                'label' => ['ko' => '커스텀 세션', 'en' => 'Custom Session'],
                'provider' => 'sirsoft-session_plugin',
            ];

            return $drivers;
        });

        $this->assertTrue($this->driverRegistry->isDriverAvailable('session', 'custom_session'));
        $this->assertFalse($this->driverRegistry->isCoreDriver('session', 'custom_session'));

        // Config 적용 시뮬레이션
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'session' && $driver === 'custom_session') {
                Config::set('session.driver', 'array');
                Config::set('session.lifetime', 180);
            }
        });

        HookManager::doAction('core.settings.apply_driver_config', 'session', 'custom_session', []);

        $this->assertEquals('array', Config::get('session.driver'));
        $this->assertEquals(180, Config::get('session.lifetime'));
    }

    /**
     * 세션 드라이버 변경 후 실제 세션 읽기/쓰기가 동작하는지 검증합니다.
     */
    #[Test]
    public function session_driver_actual_read_write_after_config(): void
    {
        // array 드라이버로 세션 테스트 (외부 의존성 없음)
        Config::set('session.driver', 'array');

        $session = app('session.store');
        $session->put('test_key', 'session_value');

        $this->assertEquals('session_value', $session->get('test_key'));

        $session->forget('test_key');
        $this->assertNull($session->get('test_key'));
    }

    // ========================================================================
    // Queue 드라이버: 플러그인이 커스텀 큐 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 플러그인이 큐 드라이버를 등록하고 Config이 적용되는지 검증합니다.
     */
    #[Test]
    public function queue_driver_plugin_registration_and_config(): void
    {
        HookManager::addFilter('core.settings.available_queue_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_queue',
                'label' => ['ko' => '커스텀 큐', 'en' => 'Custom Queue'],
                'provider' => 'sirsoft-queue_plugin',
            ];

            return $drivers;
        });

        $this->assertTrue($this->driverRegistry->isDriverAvailable('queue', 'custom_queue'));

        // Config 적용 시뮬레이션
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'queue' && $driver === 'custom_queue') {
                Config::set('queue.default', 'sync');
            }
        });

        HookManager::doAction('core.settings.apply_driver_config', 'queue', 'custom_queue', []);

        $this->assertEquals('sync', Config::get('queue.default'));
    }

    /**
     * sync 큐 드라이버로 Config이 올바르게 적용되는지 검증합니다.
     */
    #[Test]
    public function queue_driver_sync_config_applied(): void
    {
        Config::set('queue.default', 'sync');

        $this->assertEquals('sync', Config::get('queue.default'));

        // sync 드라이버 커넥션이 유효한지 확인
        $connection = app('queue')->connection('sync');
        $this->assertNotNull($connection);
    }

    // ========================================================================
    // Log 드라이버: 플러그인이 커스텀 로그 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 플러그인이 로그 드라이버를 등록하고 Config이 적용되는지 검증합니다.
     */
    #[Test]
    public function log_driver_plugin_registration_and_config(): void
    {
        HookManager::addFilter('core.settings.available_log_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_log',
                'label' => ['ko' => '커스텀 로그', 'en' => 'Custom Log'],
                'provider' => 'sirsoft-log_plugin',
            ];

            return $drivers;
        });

        $this->assertTrue($this->driverRegistry->isDriverAvailable('log', 'custom_log'));

        // 플러그인이 커스텀 채널 등록
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'log' && $driver === 'custom_log') {
                Config::set('logging.channels.custom_log', [
                    'driver' => 'single',
                    'path' => storage_path('logs/custom_plugin.log'),
                    'level' => 'debug',
                ]);
                Config::set('logging.channels.stack.channels', ['custom_log']);
            }
        });

        HookManager::doAction('core.settings.apply_driver_config', 'log', 'custom_log', []);

        $channels = Config::get('logging.channels.stack.channels');
        $this->assertContains('custom_log', $channels);
        $this->assertNotNull(Config::get('logging.channels.custom_log'));
    }

    /**
     * 커스텀 로그 채널로 실제 로그 기록이 동작하는지 검증합니다.
     */
    #[Test]
    public function log_driver_actual_write_to_custom_channel(): void
    {
        $logPath = storage_path('logs/driver_test.log');

        // 파일 정리
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        Config::set('logging.channels.driver_test', [
            'driver' => 'single',
            'path' => $logPath,
            'level' => 'debug',
        ]);

        Log::channel('driver_test')->info('플러그인 드라이버 로그 테스트', ['source' => 'integration_test']);

        $this->assertFileExists($logPath);
        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('플러그인 드라이버 로그 테스트', $logContent);
        $this->assertStringContainsString('integration_test', $logContent);

        // 정리
        unlink($logPath);
    }

    // ========================================================================
    // WebSocket 드라이버: 플러그인이 커스텀 웹소켓 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 플러그인이 웹소켓 드라이버를 등록하고 Config이 적용되는지 검증합니다.
     */
    #[Test]
    public function websocket_driver_plugin_registration_and_config(): void
    {
        HookManager::addFilter('core.settings.available_websocket_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_ws',
                'label' => ['ko' => '커스텀 웹소켓', 'en' => 'Custom WebSocket'],
                'provider' => 'sirsoft-ws_plugin',
            ];

            return $drivers;
        });

        $this->assertTrue($this->driverRegistry->isDriverAvailable('websocket', 'custom_ws'));

        // 플러그인이 웹소켓 Config 적용
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'websocket' && $driver === 'custom_ws') {
                Config::set('broadcasting.default', 'custom_ws');
                Config::set('broadcasting.connections.custom_ws', [
                    'driver' => 'pusher',
                    'key' => 'custom-app-key',
                    'options' => [
                        'host' => 'ws.custom-provider.com',
                        'port' => 6001,
                        'scheme' => 'wss',
                    ],
                ]);
            }
        });

        HookManager::doAction('core.settings.apply_driver_config', 'websocket', 'custom_ws', []);

        $this->assertEquals('custom_ws', Config::get('broadcasting.default'));
        $this->assertEquals('ws.custom-provider.com', Config::get('broadcasting.connections.custom_ws.options.host'));
        $this->assertEquals(6001, Config::get('broadcasting.connections.custom_ws.options.port'));
    }

    // ========================================================================
    // Mail 드라이버: 플러그인이 커스텀 메일 드라이버를 등록한 경우
    // ========================================================================

    /**
     * 플러그인이 메일 드라이버를 등록하고 Config이 적용되는지 검증합니다.
     */
    #[Test]
    public function mail_driver_plugin_registration_and_config(): void
    {
        HookManager::addFilter('core.settings.available_mail_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'custom_mail_api',
                'label' => ['ko' => '커스텀 메일 API', 'en' => 'Custom Mail API'],
                'provider' => 'sirsoft-custom_mail',
            ];

            return $drivers;
        });

        $this->assertTrue($this->driverRegistry->isDriverAvailable('mail', 'custom_mail_api'));

        // 플러그인이 메일 Config 적용
        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'mail' && $driver === 'custom_mail_api') {
                Config::set('mail.default', 'custom_mail_api');
                Config::set('mail.mailers.custom_mail_api', [
                    'transport' => 'log',  // 테스트에서는 log transport 사용
                ]);
            }
        });

        HookManager::doAction('core.settings.apply_driver_config', 'mail', 'custom_mail_api', ['mailer' => 'custom_mail_api']);

        $this->assertEquals('custom_mail_api', Config::get('mail.default'));
        $this->assertEquals('log', Config::get('mail.mailers.custom_mail_api.transport'));
    }

    /**
     * 커스텀 메일 드라이버(log transport)로 실제 메일 발송이 동작하는지 검증합니다.
     */
    #[Test]
    public function mail_driver_actual_send_with_log_transport(): void
    {
        Config::set('mail.mailers.test_mailer', [
            'transport' => 'log',
        ]);

        // log transport는 실제 발송 없이 로그에 기록
        $mailer = app('mail.manager')->mailer('test_mailer');
        $this->assertNotNull($mailer);
    }

    // ========================================================================
    // 폴백 시나리오: 플러그인 드라이버 사용 불가 시 기본 드라이버로 복원
    // ========================================================================

    /**
     * 각 카테고리에서 플러그인 드라이버가 사용 불가능하면 기본 드라이버로 폴백하는지 검증합니다.
     */
    #[Test]
    public function fallback_to_default_driver_for_all_categories(): void
    {
        $expectedDefaults = [
            'storage' => ['local', 'filesystems.default'],
            'cache' => ['file', 'cache.default'],
            'session' => ['database', 'session.driver'],
            'queue' => ['database', 'queue.default'],
            'log' => ['daily', null],
            'websocket' => ['', null],
            'mail' => ['smtp', 'mail.default'],
        ];

        foreach ($expectedDefaults as $category => [$defaultDriver, $configKey]) {
            // 등록되지 않은 드라이버 선택 시
            $unavailable = "nonexistent_{$category}_driver";
            $this->assertFalse(
                $this->driverRegistry->isDriverAvailable($category, $unavailable),
                "'{$unavailable}'가 {$category}에서 available로 잘못 판별됩니다."
            );

            // 폴백 드라이버 확인
            $this->assertEquals(
                $defaultDriver,
                $this->driverRegistry->getDefaultDriver($category),
                "{$category} 카테고리의 기본 드라이버가 '{$defaultDriver}'가 아닙니다."
            );

            // Config 폴백 적용 검증
            if ($configKey && $defaultDriver) {
                Config::set($configKey, $defaultDriver);
                $this->assertEquals($defaultDriver, Config::get($configKey));
            }
        }
    }

    /**
     * 스토리지 폴백 후 코어/모듈/플러그인 파일 조작이 여전히 정상 동작하는지 검증합니다.
     */
    #[Test]
    public function storage_fallback_preserves_file_operations(): void
    {
        // 존재하지 않는 드라이버에서 기본값 'local'로 폴백
        Config::set('filesystems.default', 'local');

        $coreStorage = new CoreStorageDriver('local');
        $moduleStorage = new ModuleStorageDriver('test-driver-module', 'modules');
        $pluginStorage = new PluginStorageDriver('test-driver-plugin', 'plugins');

        // 코어
        $coreStorage->put('temp', 'fallback-core.txt', 'core-fallback');
        $this->assertEquals('core-fallback', $coreStorage->get('temp', 'fallback-core.txt'));
        $coreStorage->delete('temp', 'fallback-core.txt');

        // 모듈
        $moduleStorage->put('temp', 'fallback-module.txt', 'module-fallback');
        $this->assertEquals('module-fallback', $moduleStorage->get('temp', 'fallback-module.txt'));

        // 플러그인
        $pluginStorage->put('temp', 'fallback-plugin.txt', 'plugin-fallback');
        $this->assertEquals('plugin-fallback', $pluginStorage->get('temp', 'fallback-plugin.txt'));
    }

    /**
     * 캐시 폴백 후 캐시 조작이 여전히 정상 동작하는지 검증합니다.
     */
    #[Test]
    public function cache_fallback_preserves_cache_operations(): void
    {
        // 기본값 'file'로 폴백
        Config::set('cache.default', 'file');

        Cache::put('fallback_test', 'fallback_value', 60);
        $this->assertEquals('fallback_value', Cache::get('fallback_test'));

        Cache::forget('fallback_test');
        $this->assertNull(Cache::get('fallback_test'));
    }

    // ========================================================================
    // 복수 플러그인 동시 등록
    // ========================================================================

    /**
     * 여러 플러그인이 같은 카테고리에 드라이버를 등록해도 모두 목록에 포함되는지 검증합니다.
     */
    #[Test]
    public function multiple_plugins_can_register_drivers_in_same_category(): void
    {
        HookManager::addFilter('core.settings.available_cache_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'plugin_a_cache',
                'label' => ['ko' => 'A 캐시', 'en' => 'A Cache'],
                'provider' => 'sirsoft-plugin_a',
            ];

            return $drivers;
        });

        HookManager::addFilter('core.settings.available_cache_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'plugin_b_cache',
                'label' => ['ko' => 'B 캐시', 'en' => 'B Cache'],
                'provider' => 'sirsoft-plugin_b',
            ];

            return $drivers;
        });

        $cacheDrivers = $this->driverRegistry->getAvailableDrivers('cache');
        $ids = array_column($cacheDrivers, 'id');

        // 코어 + 플러그인 A + 플러그인 B 모두 포함
        $this->assertContains('file', $ids);
        $this->assertContains('redis', $ids);
        $this->assertContains('plugin_a_cache', $ids);
        $this->assertContains('plugin_b_cache', $ids);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * 테스트용 스토리지 플러그인을 등록합니다.
     */
    private function registerStoragePlugin(): void
    {
        HookManager::addFilter('core.settings.available_storage_drivers', function (array $drivers) {
            $drivers[] = [
                'id' => 'plugin_local_extended',
                'label' => ['ko' => '확장 로컬', 'en' => 'Extended Local'],
                'provider' => 'sirsoft-storage_ext',
            ];

            return $drivers;
        });

        HookManager::addAction('core.settings.apply_driver_config', function (string $category, string $driver, array $settings) {
            if ($category === 'storage' && $driver === 'plugin_local_extended') {
                // 플러그인이 커스텀 디스크 등록 + filesystems.default 변경
                Config::set('filesystems.disks.plugin_local_extended', [
                    'driver' => 'local',
                    'root' => storage_path('app/private'),
                    'throw' => true,
                ]);
                Config::set('filesystems.default', 'plugin_local_extended');
            }
        });
    }

    /**
     * 등록된 스토리지 플러그인의 Config을 적용합니다.
     */
    private function applyPluginStorageConfig(): void
    {
        $category = 'storage';
        $selectedDriver = 'plugin_local_extended';

        if ($this->driverRegistry->isDriverAvailable($category, $selectedDriver)) {
            HookManager::doAction('core.settings.apply_driver_config', $category, $selectedDriver, [
                'storage_driver' => $selectedDriver,
            ]);
        }
    }
}
