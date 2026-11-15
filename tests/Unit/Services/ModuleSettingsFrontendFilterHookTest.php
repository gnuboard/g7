<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Services\LayoutService;
use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\Helpers\MocksExtensions;
use Tests\TestCase;

/**
 * 확장 공개 설정 필터 훅 테스트
 *
 * 프론트엔드로 내보내는 확장 설정(`getAllActiveSettings()`)에 확장이 끼어들어
 * 키를 지울 수 있는지, 잘못된 반환값(비배열)을 방어하는지 검증합니다.
 *
 * @scenario-manifest tests/scenarios/extension-frontend-settings-filter-hook.yaml
 */
class ModuleSettingsFrontendFilterHookTest extends TestCase
{
    use MocksExtensions;

    private const MODULE_FILTER = 'core.module_settings.filter_frontend_settings';

    private const PLUGIN_FILTER = 'core.plugin_settings.filter_frontend_settings';

    private string $workDir;

    /**
     * 시험용 defaults.json 을 둘 임시 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/test-modules/frontend-filter-'.uniqid());
        File::makeDirectory($this->workDir, 0755, true);
    }

    /**
     * 등록한 필터와 임시 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::MODULE_FILTER);
        HookManager::clearFilter(self::PLUGIN_FILTER);
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    /**
     * 리스너가 지운 키는 모듈 공개 설정 결과에서 빠진다.
     *
     * @scenario owner=module, listener_result=array_with_removed_key
     *
     * @effects listener_receives_settings_and_identifier, removed_key_absent_from_frontend_settings
     *
     * @return void 반환값 없음
     */
    public function test_module_filter_can_remove_exposed_keys(): void
    {
        $identifier = 'test-frontend-filter-module';
        $service = $this->makeModuleService($identifier);

        $received = [];
        HookManager::addFilter(self::MODULE_FILTER, function (array $settings, string $id) use (&$received) {
            $received[] = $id;
            unset($settings['shop_info']['phone']);

            return $settings;
        });

        $result = $service->getAllActiveSettings();

        $this->assertSame([$identifier], $received);
        $this->assertSame('상호', $result[$identifier]['shop_info']['name']);
        $this->assertArrayNotHasKey('phone', $result[$identifier]['shop_info']);
    }

    /**
     * 리스너가 배열이 아닌 값을 돌려주면 필터 전 값을 그대로 쓴다.
     *
     * @scenario owner=module, listener_result=non_array
     *
     * @effects non_array_result_is_ignored
     *
     * @return void 반환값 없음
     */
    public function test_module_filter_ignores_non_array_result(): void
    {
        $identifier = 'test-frontend-filter-module';
        $service = $this->makeModuleService($identifier);

        HookManager::addFilter(self::MODULE_FILTER, fn () => null);

        $result = $service->getAllActiveSettings();

        $this->assertSame('상호', $result[$identifier]['shop_info']['name']);
        $this->assertSame('02-000-0000', $result[$identifier]['shop_info']['phone']);
    }

    /**
     * 리스너가 전부 지워 빈 배열이 되면 그 모듈은 결과에서 빠진다.
     *
     * @scenario owner=module, listener_result=empty_array
     *
     * @effects emptied_extension_is_dropped
     *
     * @return void 반환값 없음
     */
    public function test_module_filter_emptying_settings_drops_module(): void
    {
        $identifier = 'test-frontend-filter-module';
        $service = $this->makeModuleService($identifier);

        HookManager::addFilter(self::MODULE_FILTER, fn () => []);

        $this->assertArrayNotHasKey($identifier, $service->getAllActiveSettings());
    }

    /**
     * 플러그인 공개 설정도 같은 방식의 필터 훅을 거친다.
     *
     * @scenario owner=plugin, listener_result=array_with_removed_key
     *
     * @effects listener_receives_settings_and_identifier, removed_key_absent_from_frontend_settings
     *
     * @return void 반환값 없음
     */
    public function test_plugin_filter_can_remove_exposed_keys(): void
    {
        $identifier = 'test-frontend-filter-plugin';
        $defaultsPath = $this->workDir.'/plugin-defaults.json';
        File::put($defaultsPath, json_encode([
            'defaults' => ['display_mode' => 'layer', 'popup_width' => 500],
            'frontend_schema' => [
                'display_mode' => ['expose' => true],
                'popup_width' => ['expose' => true],
            ],
        ]));

        $mockPlugin = $this->createMockPlugin([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => ['display_mode' => 'layer', 'popup_width' => 500],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $defaultsPath,
        ]);

        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getActivePlugins')->andReturn([$mockPlugin]);
        $mockPluginManager->shouldReceive('getPlugin')->with($identifier)->andReturn($mockPlugin);

        $service = new PluginSettingsService(
            $mockPluginManager,
            app(TemplateManager::class),
            app(LayoutService::class)
        );

        $received = [];
        HookManager::addFilter(self::PLUGIN_FILTER, function (array $settings, string $id) use (&$received) {
            $received[] = $id;
            unset($settings['popup_width']);

            return $settings;
        });

        $result = $service->getAllActiveSettings();

        $this->assertSame([$identifier], $received);
        $this->assertSame('layer', $result[$identifier]['display_mode']);
        $this->assertArrayNotHasKey('popup_width', $result[$identifier]);
    }

    /**
     * 공개 필드 두 개를 가진 가짜 모듈로 서비스를 만든다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return ModuleSettingsService 서비스
     */
    private function makeModuleService(string $identifier): ModuleSettingsService
    {
        $defaultsPath = $this->workDir.'/module-defaults.json';
        File::put($defaultsPath, json_encode([
            'defaults' => [
                'shop_info' => ['name' => '상호', 'phone' => '02-000-0000'],
            ],
            'frontend_schema' => [
                'shop_info' => ['expose' => true],
            ],
        ]));

        $mockModule = $this->createMockModule([
            'identifier' => $identifier,
            'hasSettings' => true,
            'configValues' => [
                'shop_info' => ['name' => '상호', 'phone' => '02-000-0000'],
            ],
            'settingsSchema' => [],
            'settingsDefaultsPath' => $defaultsPath,
            'storage' => ['exists' => false],
        ]);

        $mockModuleManager = Mockery::mock(ModuleManager::class);
        $mockModuleManager->shouldReceive('getActiveModules')->andReturn([$mockModule]);
        $mockModuleManager->shouldReceive('getModule')->with($identifier)->andReturn($mockModule)->zeroOrMoreTimes();

        return new ModuleSettingsService(
            $mockModuleManager,
            app(TemplateManager::class),
            app(LayoutService::class)
        );
    }
}
