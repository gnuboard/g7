<?php

namespace Tests\Feature\Settings;

use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\StorageInterface;
use App\Extension\PluginManager;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Services\PluginSettingsService;
use App\Services\SettingsService;
use App\Support\ExtensionSettingsMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Mockery;
use Modules\Sirsoft\Page\Services\PageSettingsService;
use Tests\TestCase;

/**
 * 설정 config 미러 재채움 회귀 테스트 (공개이슈 #109)
 *
 * `g7_settings.*` 미러는 종전에 부팅 때 한 번만 채워졌다. FPM 은 요청마다 부팅해
 * 문제가 드러나지 않지만, 큐 워커·schedule:work·Reverb 처럼 상주하는 프로세스에서는
 * 관리자가 저장해도 그 프로세스가 옛 값을 영원히 읽는다.
 *
 * 아래 테스트는 **한 프로세스 안에서** 저장 후 미러가 갱신되는지를 본다 —
 * 재부팅으로는 결함이 드러나지 않기 때문이다.
 *
 * @scenario scope=core, scope=plugin, trigger=save, trigger=boot, field_sensitivity=sensitive
 *
 * @effects core_mirror_refreshed_after_save, core_mirror_refreshed_after_set_setting, plugin_mirror_refreshed_after_save, plugin_mirror_merges_defaults, sensitive_fields_absent_from_mirror_on_boot, sensitive_fields_absent_from_mirror_on_refresh, sensitive_fields_still_readable_via_dedicated_getter, page_module_mirror_exists
 */
class SettingsConfigMirrorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A1: 코어 설정 저장 후 같은 프로세스의 미러가 신값을 읽는다.
     */
    public function test_core_settings_mirror_is_refreshed_after_save(): void
    {
        Config::set('g7_settings.core.general.site_name', '옛 이름');

        app(SettingsService::class)->saveSettings([
            'general' => ['site_name' => '새 이름'],
        ]);

        $this->assertSame('새 이름', g7_core_settings('general.site_name'));
    }

    /**
     * A1: setSetting() 경로도 같은 무효화 처리를 받는다.
     */
    public function test_set_setting_also_refreshes_core_mirror(): void
    {
        Config::set('g7_settings.core.general.site_name', '옛 이름');

        app(SettingsService::class)->setSetting('general.site_name', '단건 저장 이름');

        $this->assertSame('단건 저장 이름', g7_core_settings('general.site_name'));
    }

    /**
     * A1/A3: 부팅 경로도 컨테이너의 미러 인스턴스를 거친다.
     *
     * 부팅과 저장이 서로 다른 인스턴스를 만들면 한쪽 구현만 고쳐도 초록으로 남는다 —
     * 컨테이너 단일 해석이 "두 경로가 같은 코드를 쓴다" 의 실질적 근거다.
     *
     * @effects boot_and_save_share_one_fill_implementation
     */
    public function test_boot_path_fills_the_mirror_through_the_container_instance(): void
    {
        $spy = new class extends ExtensionSettingsMirror
        {
            /** @var int refreshCore 호출 횟수 */
            public int $coreRefreshes = 0;

            public function refreshCore(?JsonConfigRepository $configRepository = null): void
            {
                $this->coreRefreshes++;
            }
        };

        $this->app->instance(ExtensionSettingsMirror::class, $spy);

        $provider = new SettingsServiceProvider($this->app);
        $load = new \ReflectionMethod($provider, 'loadCoreSettingsToConfig');
        $load->setAccessible(true);
        $load->invoke($provider, new JsonConfigRepository);

        $this->assertSame(
            1,
            $spy->coreRefreshes,
            '부팅 경로가 컨테이너 미러를 거치지 않고 자체 인스턴스를 만듭니다'
        );
    }

    /**
     * A2/A4: 플러그인 미러는 defaults 병합·복호화를 마친 값(전용 게터와 같은 형태)을 담는다.
     *
     * @effects plugin_mirror_matches_dedicated_getter_shape
     */
    public function test_plugin_settings_mirror_uses_the_service_value_not_raw_file(): void
    {
        $this->bindStubPlugin([
            'api_key' => ['sensitive' => true],
            'endpoint' => ['sensitive' => false],
        ], [
            'api_key' => 'super-secret-value',
            'endpoint' => 'https://example.com',
            'from_defaults' => 'default-value',
        ]);

        app(ExtensionSettingsMirror::class)->refreshPlugin('stub-plugin');

        $mirror = g7_plugin_settings('stub-plugin');

        $this->assertIsArray($mirror);
        // raw 파일 읽기였다면 defaults 만 있는 키는 미러에 없다
        $this->assertSame('default-value', $mirror['from_defaults'] ?? null);
        $this->assertSame('https://example.com', $mirror['endpoint'] ?? null);

        // 키 몇 개만 보면 형태가 갈려도 통과한다 — 전용 게터 결과에서 민감 키만 뺀 것과
        // 정확히 같아야 한다(형태 동일성).
        $viaGetter = (array) app(PluginSettingsService::class)->get('stub-plugin');

        $this->assertSame(
            Arr::except($viaGetter, ['api_key']),
            $mirror,
            '미러가 전용 게터와 다른 형태의 값을 담고 있습니다'
        );
    }

    /**
     * A6/A7: 민감 필드는 미러에 담기지 않는다 (건별 재채움 / 전체 재채움 양쪽).
     *
     * 미러는 봇 전용 화면 생성기가 키 제한 없이 조회하는 경로이므로, 민감값이 실리면
     * 레이아웃이 그 키를 참조하는 순간 평문이 봇 HTML 로 나간다.
     */
    public function test_sensitive_plugin_fields_never_reach_the_mirror(): void
    {
        $this->bindStubPlugin([
            'api_key' => ['sensitive' => true],
            'endpoint' => ['sensitive' => false],
        ], [
            'api_key' => 'super-secret-value',
            'endpoint' => 'https://example.com',
        ]);

        $mirror = app(ExtensionSettingsMirror::class);

        $mirror->refreshPlugin('stub-plugin');

        $this->assertArrayNotHasKey('api_key', (array) g7_plugin_settings('stub-plugin'));
        $this->assertArrayNotHasKey('api_key', (array) Config::get('g7_settings.plugins.stub-plugin'));
        $this->assertSame('https://example.com', g7_plugin_settings('stub-plugin', 'endpoint'));

        // 부팅 경로(전체 재채움)에서도 동일 — 한쪽만 막고 다른 쪽에서 새는 회귀 차단
        $mirror->refreshAllPlugins();

        $this->assertArrayNotHasKey('api_key', (array) g7_plugin_settings('stub-plugin'));

        // 전용 게터 경로는 살아 있다 (기능 손실 없음)
        $this->assertSame('super-secret-value', app(PluginSettingsService::class)->get('stub-plugin', 'api_key'));
    }

    /**
     * A2/A7: 플러그인 저장 경로가 미러 재채움을 호출하고, 그 결과에 민감값이 없다.
     *
     * @effects sensitive_fields_absent_from_mirror_after_save
     */
    public function test_plugin_save_triggers_mirror_refresh(): void
    {
        $spy = new class extends ExtensionSettingsMirror
        {
            /** @var array<int, string> 재채움 요청을 받은 식별자 */
            public array $refreshed = [];

            public function refreshPlugin(string $identifier): void
            {
                $this->refreshed[] = $identifier;
            }
        };

        $this->app->instance(ExtensionSettingsMirror::class, $spy);

        $this->bindStubPlugin([
            'endpoint' => ['sensitive' => false],
            'api_key' => ['sensitive' => true],
        ], [
            'endpoint' => 'https://example.com',
            'api_key' => 'initial-secret',
        ]);

        app(PluginSettingsService::class)->save('stub-plugin', [
            'endpoint' => 'https://changed.example.com',
            'api_key' => 'rotated-secret',
        ]);

        $this->assertContains('stub-plugin', $spy->refreshed, '플러그인 저장이 미러를 갱신하지 않았습니다');

        // 호출만 세면 재채움이 빈 값을 써도 통과한다 — 실제 미러에 신값이 담기는지 함께 본다.
        $this->app->forgetInstance(ExtensionSettingsMirror::class);
        app(ExtensionSettingsMirror::class)->refreshPlugin('stub-plugin');

        $this->assertSame(
            'https://changed.example.com',
            g7_plugin_settings('stub-plugin', 'endpoint'),
            '재채움이 저장된 신값을 담지 않았습니다'
        );

        // 실제 저장 경로를 거친 뒤에도 민감값은 미러에 실리지 않는다 —
        // 직접 refreshPlugin() 만 호출한 케이스는 저장이 만든 페이로드를 통과시키지 않는다.
        $mirror = (array) g7_plugin_settings('stub-plugin');

        $this->assertArrayNotHasKey('api_key', $mirror);
        $this->assertStringNotContainsString(
            'rotated-secret',
            (string) json_encode($mirror, JSON_UNESCAPED_UNICODE),
            '저장 직후 재채움 경로로 민감값이 미러에 실렸습니다'
        );

        // 전용 게터로는 회전된 신값이 읽힌다 (기능 손실 없음).
        $this->assertSame('rotated-secret', app(PluginSettingsService::class)->get('stub-plugin', 'api_key'));
    }

    /**
     * A8: 백업 복원도 미러를 다시 채운다.
     *
     * 복원은 설정 전체를 갈아엎는 쓰기다. 캐시만 비우면 같은 프로세스가 복원 전 값을
     * 계속 읽어, 운영자가 "복원했는데 아무것도 안 바뀐" 상태를 본다.
     *
     * @effects core_mirror_refreshed_after_restore
     */
    public function test_restore_refreshes_the_core_mirror(): void
    {
        $service = app(SettingsService::class);

        $service->saveSettings(['general' => ['site_name' => '복원 전 이름']]);
        $backupPath = $service->backupSettings();

        $service->saveSettings(['general' => ['site_name' => '복원 후 바뀐 이름']]);
        $this->assertSame('복원 후 바뀐 이름', g7_core_settings('general.site_name'));

        $this->assertTrue($service->restoreSettings($backupPath));

        $this->assertSame(
            '복원 전 이름',
            g7_core_settings('general.site_name'),
            '복원 후에도 미러가 복원 전 값을 유지합니다'
        );
    }

    /**
     * A9: 플러그인 설정 초기화도 미러를 다시 채운다.
     *
     * @effects plugin_mirror_refreshed_after_reset
     */
    public function test_plugin_reset_refreshes_the_mirror(): void
    {
        $this->bindStubPlugin(
            ['endpoint' => ['sensitive' => false]],
            ['endpoint' => 'https://default.example.com']
        );

        $service = app(PluginSettingsService::class);

        $service->save('stub-plugin', ['endpoint' => 'https://saved.example.com']);
        $this->assertSame('https://saved.example.com', g7_plugin_settings('stub-plugin', 'endpoint'));

        $service->reset('stub-plugin');

        $this->assertSame(
            'https://default.example.com',
            g7_plugin_settings('stub-plugin', 'endpoint'),
            '초기화 후에도 미러가 초기화 전 값을 유지합니다'
        );
    }

    /**
     * A5: sirsoft-page 미러가 존재하고 defaults.json 값을 담는다.
     */
    public function test_page_module_mirror_exists(): void
    {
        if (! class_exists(PageSettingsService::class)) {
            $this->markTestSkipped('sirsoft-page 모듈이 설치되어 있지 않습니다.');
        }

        Config::set('g7_settings.modules.sirsoft-page', null);

        app(ExtensionSettingsMirror::class)->refreshModule('sirsoft-page');

        $expected = app(PageSettingsService::class)
            ->getSetting('attachment.max_count');

        $this->assertNotNull($expected, 'sirsoft-page 설정 서비스가 값을 돌려주지 않습니다');
        $this->assertSame(
            $expected,
            g7_module_settings('sirsoft-page', 'attachment.max_count'),
            'sirsoft-page 미러가 설정 서비스와 다른 값을 담고 있습니다'
        );
    }

    /**
     * 스키마/기본값을 지정한 스텁 플러그인을 활성 플러그인으로 등록합니다.
     *
     * 어떤 플러그인이 설치돼 있느냐에 결과가 좌우되지 않도록 통제된 스키마를 쓴다 —
     * 검증 대상은 미러의 민감 필드 제거·값 형태 로직 자체다.
     *
     * @param  array<string, array<string, mixed>>  $schema  설정 스키마
     * @param  array<string, mixed>  $defaults  설정 기본값
     */
    private function bindStubPlugin(array $schema, array $defaults): void
    {
        // 저장 내용을 실제로 보관하는 인메모리 스토리지 — put 한 값이 get 으로 돌아와야
        // "저장 → 재채움 → 신값" 사슬을 값 수준에서 검증할 수 있다.
        $written = new \ArrayObject;

        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('exists')->andReturnUsing(
            fn ($dir, $file) => isset($written[$file])
        );
        $storage->shouldReceive('get')->andReturnUsing(
            fn ($dir, $file) => $written[$file] ?? ''
        );
        $storage->shouldReceive('put')->andReturnUsing(function ($dir, $file, $content) use ($written) {
            $written[$file] = $content;

            return true;
        });
        $storage->shouldReceive('delete')->andReturnUsing(function ($dir, $file) use ($written) {
            unset($written[$file]);

            return true;
        });

        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getStorage')->andReturn($storage);
        $plugin->shouldReceive('getIdentifier')->andReturn('stub-plugin');
        $plugin->shouldReceive('getSettingsSchema')->andReturn($schema);
        $plugin->shouldReceive('getConfigValues')->andReturn($defaults);
        $plugin->shouldReceive('hasSettings')->andReturn(true);
        $plugin->shouldReceive('getSettingsDefaultsPath')->andReturn(null);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getPlugin')->with('stub-plugin')->andReturn($plugin);
        $pluginManager->shouldReceive('getPlugin')->andReturn(null);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn(['stub-plugin' => $plugin]);

        $this->app->instance(PluginManager::class, $pluginManager);
        $this->app->forgetInstance(PluginSettingsService::class);
    }
}
