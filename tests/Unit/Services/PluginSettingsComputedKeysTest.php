<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 확장 설정의 계산값 키 — 저장하지 않고 늘 실시간 값 (7.1.3).
 *
 * 플러그인 설정은 `array_merge(기본값, 저장값)` 이라 저장값이 이긴다. 설치 때 계산값(확장 훅 결과 등)이 설정 파일에
 * 굳으면 이후 실시간 값을 영원히 덮었다(GDPR 필수 저장 키). 계산값 키는 읽을 때 저장값을 무시하고, 저장할 때 요청·
 * 기존 파일 양쪽에서 빼며, 설치 때 쓰지 않는다. 설치 시드는 `defaults` 절이 없으면 설정 스키마에 있는 키만 쓴다.
 *
 * @scenario-manifest tests/scenarios/computed-setting-keys.yaml
 */
class PluginSettingsComputedKeysTest extends TestCase
{
    /** @var array<string, string> 가짜 저장소 내용 (category/file => 내용) */
    private array $files = [];

    /** @var string 실시간 계산값 (시험이 바꾼다) */
    private string $live = 'live-1';

    /**
     * 시험 플러그인의 설치 시드 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(ExtensionStoragePath::plugin('zztest-computed'));
        parent::tearDown();
    }

    /**
     * 저장 파일에 남은 옛 계산값은 무시하고 실시간 값을 돌려준다.
     *
     * @scenario owner=plugin, path=read
     *
     * @effects computed_key_read_is_always_live
     *
     * @return void 반환값 없음
     */
    public function test_plugin_read_ignores_stale_saved_computed_value(): void
    {
        $this->files['settings/setting.json'] = json_encode(['provided' => 'stale-from-install', 'title' => 'saved']);
        $service = $this->pluginService();

        $settings = $service->get('zztest-computed');

        $this->assertSame('live-1', $settings['provided'], '계산값은 실시간');
        $this->assertSame('saved', $settings['title'], '일반 키는 저장값이 이긴다(종전 그대로)');
    }

    /**
     * 저장하면 요청·기존 파일 양쪽에서 계산값 키가 빠진다.
     *
     * @scenario owner=plugin, path=save
     *
     * @effects computed_key_never_written_on_save
     *
     * @return void 반환값 없음
     */
    public function test_plugin_save_drops_computed_keys(): void
    {
        $this->files['settings/setting.json'] = json_encode(['provided' => 'stale-from-install', 'title' => 'old']);
        $service = $this->pluginService();

        $this->assertTrue($service->save('zztest-computed', ['title' => 'new', 'provided' => 'forged']));

        $saved = json_decode($this->files['settings/setting.json'], true);
        $this->assertSame('new', $saved['title']);
        $this->assertArrayNotHasKey('provided', $saved);
    }

    /**
     * defaults 절이 없는 플러그인의 설치 시드는 스키마 키만, 계산값 키는 빼고 쓴다.
     *
     * @scenario owner=plugin, path=install_seed
     *
     * @effects install_seed_writes_schema_keys_only
     *
     * @return void 반환값 없음
     */
    public function test_plugin_install_seed_writes_schema_keys_without_computed(): void
    {
        $plugin = $this->fakePlugin();
        $manager = app(PluginManager::class);

        (fn () => $this->initializePluginSettings($plugin))->call($manager);

        $path = ExtensionStoragePath::plugin('zztest-computed', 'settings').'/setting.json';
        $this->assertFileExists($path);
        $this->assertSame(['title' => 'default-title'], json_decode(File::get($path), true), '스키마 밖 키(fixed)·계산값 키(provided)는 쓰지 않는다');
    }

    /**
     * 모듈도 저장 파일의 옛 계산값을 무시한다.
     *
     * @scenario owner=module, path=read
     *
     * @effects computed_key_read_is_always_live
     *
     * @return void 반환값 없음
     */
    public function test_module_read_ignores_stale_saved_computed_value(): void
    {
        $this->files['settings/setting.json'] = json_encode(['provided' => 'stale', 'title' => 'saved']);
        $module = new class($this) extends AbstractModule
        {
            /**
             * 시험 인스턴스를 받습니다.
             *
             * @param  PluginSettingsComputedKeysTest  $test  가짜 저장소·실시간 값을 가진 시험
             */
            public function __construct(private PluginSettingsComputedKeysTest $test) {}

            /**
             * 설정 기본값(실시간 계산값 포함)을 돌려줍니다.
             *
             * @return array<string, mixed> 기본값
             */
            public function getConfigValues(): array
            {
                return ['provided' => $this->test->liveValue(), 'title' => 'default-title'];
            }

            /**
             * 계산값 키를 선언합니다.
             *
             * @return array<int, string> 계산값 키
             */
            public function getComputedSettingKeys(): array
            {
                return ['provided'];
            }

            /**
             * 가짜 저장소를 돌려줍니다.
             *
             * @return StorageInterface 가짜 저장소
             */
            public function getStorage(): StorageInterface
            {
                return $this->test->fakeStorage();
            }
        };

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getModule')->andReturn($module);
        $this->app->instance(ModuleManager::class, $manager);

        $settings = app(ModuleSettingsService::class)->get('zztest-computed');

        $this->assertSame('live-1', $settings['provided']);
        $this->assertSame('saved', $settings['title']);
    }

    /**
     * 현재 실시간 계산값을 돌려줍니다 (가짜 확장이 부른다).
     *
     * @return string 실시간 값
     */
    public function liveValue(): string
    {
        return $this->live;
    }

    /**
     * 시험 배열을 쓰는 가짜 저장소를 만듭니다.
     *
     * @return StorageInterface 가짜 저장소
     */
    public function fakeStorage(): StorageInterface
    {
        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('exists')->andReturnUsing(fn ($category, $file) => isset($this->files[$category.'/'.$file]));
        $storage->shouldReceive('get')->andReturnUsing(fn ($category, $file) => $this->files[$category.'/'.$file] ?? null);
        $storage->shouldReceive('put')->andReturnUsing(function ($category, $file, $content) {
            $this->files[$category.'/'.$file] = $content;

            return true;
        });

        return $storage;
    }

    /**
     * 가짜 플러그인을 돌려주는 매니저로 플러그인 설정 서비스를 만듭니다.
     *
     * @return PluginSettingsService 서비스
     */
    private function pluginService(): PluginSettingsService
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('getPlugin')->andReturn($this->fakePlugin());
        $manager->shouldReceive('getActivePlugins')->andReturn([]);
        $this->app->instance(PluginManager::class, $manager);

        return app(PluginSettingsService::class);
    }

    /**
     * 계산값 키(provided)·스키마 키(title)·스키마 밖 고정 키(fixed)를 가진 가짜 플러그인.
     *
     * @return AbstractPlugin 플러그인
     */
    private function fakePlugin(): AbstractPlugin
    {
        $plugin = new class($this) extends AbstractPlugin
        {
            /**
             * 시험 인스턴스를 받습니다.
             *
             * @param  PluginSettingsComputedKeysTest  $test  가짜 저장소·실시간 값을 가진 시험
             */
            public function __construct(private PluginSettingsComputedKeysTest $test) {}

            /**
             * 설정 기본값(실시간 계산값 포함)을 돌려줍니다.
             *
             * @return array<string, mixed> 기본값
             */
            public function getConfigValues(): array
            {
                return ['provided' => $this->test->liveValue(), 'title' => 'default-title', 'fixed' => 'frozen-at-install'];
            }

            /**
             * 계산값 키를 선언합니다.
             *
             * @return array<int, string> 계산값 키
             */
            public function getComputedSettingKeys(): array
            {
                return ['provided'];
            }

            /**
             * 설정 스키마를 돌려줍니다.
             *
             * @return array<string, array<string, mixed>> 설정 스키마
             */
            public function getSettingsSchema(): array
            {
                return ['title' => ['type' => 'string'], 'provided' => ['type' => 'array']];
            }

            /**
             * 가짜 저장소를 돌려줍니다.
             *
             * @return StorageInterface 가짜 저장소
             */
            public function getStorage(): StorageInterface
            {
                return $this->test->fakeStorage();
            }
        };
        (new \ReflectionProperty(AbstractPlugin::class, 'pluginPath'))->setValue($plugin, storage_path('app/temp/zztest-computed'));

        return $plugin;
    }
}
