<?php

namespace Plugins\Sirsoft\DaumPostcode\Tests;

use App\Extension\HookListenerRegistrar;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\DaumPostcode\Plugin;
use Tests\TestCase;

/**
 * 다음 우편번호 플러그인 테스트 베이스.
 *
 * 코어 플러그인 시스템은 테스트 환경에서 자동 부팅되지 않으므로 다국어 네임스페이스, 플러그인 인스턴스,
 * 훅 리스너를 여기서 등록한다. 설정 저장 테스트가 개발 사이트 설정 파일을 덮지 않도록 'plugins' 디스크를
 * 테스트마다 임시 루트로 돌린다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /** 플러그인 식별자 */
    protected const IDENTIFIER = 'sirsoft-daum_postcode';

    /** 테스트 격리용 임시 plugins 스토리지 루트 (setUp 에서 만들고 tearDown 에서 지운다) */
    private ?string $isolatedPluginsRoot = null;

    /**
     * 스토리지 격리 → 다국어 → 플러그인 인스턴스 → 훅 리스너 순으로 실행 환경을 꾸린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatePluginStorage();

        // 플러그인 lang 네임스페이스 — 실 환경에서는 코어 TranslationServiceProvider 가 활성 디렉토리의
        // lang 을 등록한다. 테스트는 번들 원본을 검증하므로 _bundled 경로를 등록한다.
        $translator = $this->app['translator'];
        $translator->getLoader()->addNamespace(self::IDENTIFIER, base_path('plugins/_bundled/'.self::IDENTIFIER.'/lang'));

        // 부팅 중 빈 값으로 캐시된 네임스페이스와 validation 그룹을 버리고 실제 파일을 다시 읽게 한다.
        $loadedProp = new \ReflectionProperty($translator, 'loaded');
        $loadedProp->setAccessible(true);
        $loaded = $loadedProp->getValue($translator);
        unset($loaded[self::IDENTIFIER], $loaded['*']['validation']);
        $loadedProp->setValue($translator, $loaded);
        $translator->get('validation.required');

        $this->registerPluginInstance();
        $this->registerPluginHookListeners();
    }

    /**
     * 격리했던 플러그인 스토리지를 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restorePluginStorage();

        parent::tearDown();
    }

    /**
     * 코어 PluginManager 가 이 플러그인 인스턴스를 돌려주게 한다(설정 저장 요청이 설정 스키마를 읽는다).
     *
     * @return void 반환값 없음
     */
    protected function registerPluginInstance(): void
    {
        $manager = app(PluginManager::class);
        $prop = new \ReflectionProperty($manager, 'plugins');
        $prop->setAccessible(true);
        $plugins = $prop->getValue($manager);
        $plugins[self::IDENTIFIER] = new Plugin;
        $prop->setValue($manager, $plugins);
    }

    /**
     * 플러그인이 선언한 훅 리스너를 코어 HookManager 에 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginHookListeners(): void
    {
        foreach ((new Plugin)->getHookListeners() as $listener) {
            HookListenerRegistrar::register($listener, 'plugin:'.self::IDENTIFIER);
        }
    }

    /**
     * 'plugins' 디스크 루트를 이 테스트만의 임시 디렉토리로 바꾼다.
     *
     * @return void 반환값 없음
     */
    private function isolatePluginStorage(): void
    {
        $this->isolatedPluginsRoot = storage_path('framework/testing/plugin-storage-'.uniqid('', true));

        File::ensureDirectoryExists($this->isolatedPluginsRoot);

        config(['filesystems.disks.plugins.root' => $this->isolatedPluginsRoot]);

        Storage::forgetDisk('plugins');
    }

    /**
     * 임시 plugins 루트를 지운다.
     *
     * @return void 반환값 없음
     */
    private function restorePluginStorage(): void
    {
        if ($this->isolatedPluginsRoot !== null && is_dir($this->isolatedPluginsRoot)) {
            File::deleteDirectory($this->isolatedPluginsRoot);
        }

        $this->isolatedPluginsRoot = null;

        Storage::forgetDisk('plugins');
    }
}
