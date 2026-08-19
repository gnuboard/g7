<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\PluginManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Plugin;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * getStorageDiskFor 설정 로드 순환 참조 회귀 테스트 (실제 설정 로드 경로)
 *
 * 실제 플러그인 인스턴스의 getStorageDiskFor 가 plugin_setting() 을 경유할 때,
 * 설정 로드 자신이 스토리지('settings' 카테고리)를 다시 요구하는 상황을
 * 컨테이너 스파이로 재현합니다. PUBLIC_ASSET_CATEGORIES 게이트가 제거되면
 * 'settings' 해석이 설정 서비스를 재호출해 호출 횟수 단언이 깨집니다.
 */
class StorageDiskRecursionTest extends PluginTestCase
{
    private Plugin $plugin;

    /**
     * 설정 로드가 스토리지를 경유하는 경로를 재현하는 스파이
     */
    private object $settingsSpy;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        Config::set('core.storage.public_asset_disk', '');

        $this->plugin = app(PluginManager::class)->getPlugin('sirsoft-ckeditor5')
            ?? new Plugin(base_path('plugins/sirsoft-ckeditor5'));

        // 실제 설정 로드처럼 get() 내부에서 플러그인 스토리지('settings')를 재요구하는 스파이.
        // 재진입(= getStorageDiskFor('settings') 가 다시 설정을 조회하는 회귀) 발생 시
        // reentered 플래그 + 호출 횟수 증가로 검출됩니다.
        $this->settingsSpy = new class extends PluginSettingsService
        {
            public ?Plugin $plugin = null;

            public int $calls = 0;

            public bool $reentered = false;

            public ?string $nestedDisk = null;

            private bool $inGet = false;

            public function __construct() {}

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                $this->calls++;

                if ($this->inGet) {
                    // 설정 로드 중 설정 재조회 = 순환 고리. 무한 재귀 대신 폭로.
                    $this->reentered = true;

                    return $default;
                }

                $this->inGet = true;

                try {
                    // 설정 로드가 스토리지를 경유하는 실제 경로 재현 —
                    // 이 호출이 getStorageDiskFor('settings') 를 타고 되돌아온다.
                    $this->nestedDisk = $this->plugin->getStorageFor('settings')->getDisk();

                    if ($key === 'public_asset_disk') {
                        return 'fake_cdn';
                    }

                    return $default;
                } finally {
                    $this->inGet = false;
                }
            }
        };
        $this->settingsSpy->plugin = $this->plugin;
        $this->app->instance(PluginSettingsService::class, $this->settingsSpy);
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(PluginSettingsService::class);

        parent::tearDown();
    }

    /**
     * @effects settings_load_recursion_free
     */
    #[Test]
    public function settings_category_never_consults_plugin_settings(): void
    {
        // 'settings' 카테고리 해석은 설정 서비스를 한 번도 거치지 않아야 한다 —
        // 거치는 순간 설정 로드 ↔ 디스크 해석의 순환 고리가 열린다.
        $this->assertSame($this->plugin->getStorageDisk(), $this->plugin->getStorageDiskFor('settings'));
        $this->assertSame(0, $this->settingsSpy->calls);
    }

    /**
     * @effects settings_load_recursion_free
     */
    #[Test]
    public function images_resolution_survives_settings_load_that_uses_storage(): void
    {
        // 공개 자산 카테고리 해석 → 설정 조회 → (설정 로드가 'settings' 스토리지 재요구)
        // 전체 사슬이 유한 종료하고, 설정 조회는 정확히 1회만 발생해야 한다.
        $this->assertSame('fake_cdn', $this->plugin->getStorageDiskFor('images'));

        $this->assertSame(1, $this->settingsSpy->calls);
        $this->assertFalse($this->settingsSpy->reentered, '설정 로드 중 설정 재조회(순환) 발생');
        // 설정 로드가 요구한 'settings' 스토리지는 공개 자산 디스크가 아닌 기본 디스크
        $this->assertSame($this->plugin->getStorageDisk(), $this->settingsSpy->nestedDisk);
    }
}
