<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 모듈/플러그인 상태 캐시 통합 테스트
 *
 * 새 CacheInterface 기반 캐시 키(`g7:core:ext.modules.active_identifiers` 등)와
 * Trait 의 캐시 무효화 동작을 검증합니다.
 *
 * "빈 배열 캐시 금지" 정책은 한때 폐기되고 명시적 무효화(invalidateModuleStatusCache)에만
 * 의존했으나, 그 구조로는 회복 불가능한 실패가 실제로 발생하여 되살렸습니다:
 * DB 에는 활성 모듈 3건이 있는데 캐시에는 빈 배열이 남아, 모든 모듈 관리자 화면이 404 가
 * 되고 TTL(기본 하루) 이 지나기 전까지 스스로 회복되지 않았습니다. 확장 작업 도중 상태가
 * 잠시 active 가 아닌 창이 존재하는 한, 그 순간의 읽기를 막을 방법은 없습니다.
 * 따라서 명시적 무효화는 그대로 두되, **빈 결과는 애초에 캐시하지 않습니다**.
 */
class ModuleCacheTest extends TestCase
{
    private const MODULE_ACTIVE_KEY = 'g7:core:ext.modules.active_identifiers';

    private const PLUGIN_ACTIVE_KEY = 'g7:core:ext.plugins.active_identifiers';

    private const TEMP_MODULE = 'vendor-status_window_module';

    private const TEMP_PLUGIN = 'vendor-status_window_plugin';

    protected function setUp(): void
    {
        parent::setUp();

        // 확장 상태 캐시는 개발 환경과 **같은 저장소**를 쓴다. 테스트 DB 에는 확장 행이 없으므로,
        // 여기서 계산한 "활성 확장 없음" 이 공유 캐시에 실리면 개발 사이트가 그 값을 읽어
        // 모든 모듈 화면이 404 가 된다(실제로 발생). 이 클래스만 메모리 저장소로 격리한다.
        config(['cache.default' => 'array']);

        Cache::store('array')->flush();
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();
        parent::tearDown();
    }

    /**
     * 활성 모듈이 있을 때 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_non_empty_active_modules(): void
    {
        $activeModule = Module::where('status', ExtensionStatus::Active->value)->first();
        if (! $activeModule) {
            $this->markTestSkipped('활성 모듈이 없습니다.');
        }

        ModuleManager::invalidateModuleStatusCache();
        $result = ModuleManager::getActiveModuleIdentifiers();

        $this->assertNotEmpty($result, '활성 모듈 목록이 비어있으면 안됩니다');

        $cached = Cache::get(self::MODULE_ACTIVE_KEY);
        $this->assertNotNull($cached, '활성 모듈 목록이 캐시되어야 합니다');
        $this->assertEquals($result, $cached);
    }

    /**
     * 모듈 상태 변경 후 캐시 무효화가 정상 동작해야 합니다.
     */
    #[Test]
    public function it_invalidates_cache_on_status_change(): void
    {
        $initialResult = ModuleManager::getActiveModuleIdentifiers();

        if (empty($initialResult)) {
            $this->markTestSkipped('활성 모듈이 없습니다.');
        }

        $this->assertNotNull(Cache::get(self::MODULE_ACTIVE_KEY), '초기 캐시가 생성되어야 합니다');

        ModuleManager::invalidateModuleStatusCache();

        $this->assertNull(Cache::get(self::MODULE_ACTIVE_KEY), '캐시가 무효화되어야 합니다');
    }

    /**
     * 활성 플러그인이 있을 때 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_non_empty_active_plugins(): void
    {
        $activePlugin = Plugin::where('status', ExtensionStatus::Active->value)->first();
        if (! $activePlugin) {
            $this->markTestSkipped('활성 플러그인이 없습니다.');
        }

        PluginManager::invalidatePluginStatusCache();
        $result = PluginManager::getActivePluginIdentifiers();

        $this->assertNotEmpty($result, '활성 플러그인 목록이 비어있으면 안됩니다');

        $cached = Cache::get(self::PLUGIN_ACTIVE_KEY);
        $this->assertNotNull($cached, '활성 플러그인 목록이 캐시되어야 합니다');
        $this->assertEquals($result, $cached);
    }

    /**
     * 빈 결과는 캐시에 남지 않아야 합니다 (모듈).
     *
     * 확장 작업 중 상태가 잠시 active 가 아닌 창에서 이 목록을 읽으면 빈 배열이 하루 동안
     * 굳는다. 그 뒤 상태가 정상으로 돌아와도 모든 모듈이 꺼진 것처럼 동작한다.
     */
    #[Test]
    public function it_does_not_cache_empty_active_modules(): void
    {
        // 이 클래스는 RefreshDatabase 를 쓰지 않는다(실제 설치 상태를 읽는 테스트가 섞여 있음).
        // 따라서 원래 상태를 기억했다가 반드시 되돌린다.
        $originalStatuses = Module::query()->pluck('status', 'identifier')->all();
        Module::where('identifier', self::TEMP_MODULE)->delete();

        try {
            $module = Module::factory()->create([
                'identifier' => self::TEMP_MODULE,
                'status' => ExtensionStatus::Inactive->value,
            ]);

            // 확장 작업 중 상태 창 재현 — 활성 모듈이 하나도 없는 순간
            Module::query()->update(['status' => ExtensionStatus::Inactive->value]);
            ModuleManager::invalidateModuleStatusCache();

            $this->assertSame([], ModuleManager::getActiveModuleIdentifiers());
            $this->assertNull(
                Cache::get(self::MODULE_ACTIVE_KEY),
                '빈 결과가 캐시되면 상태가 정상으로 돌아와도 하루 동안 모든 모듈이 꺼진 것으로 보입니다.'
            );

            // 상태 복원 후 무효화 없이도 즉시 반영되어야 한다
            $module->update(['status' => ExtensionStatus::Active->value]);

            $this->assertContains(
                self::TEMP_MODULE,
                ModuleManager::getActiveModuleIdentifiers(),
                '상태가 정상으로 돌아왔는데도 빈 목록이 반환됩니다 — 스스로 회복되지 않는 상태입니다.'
            );
        } finally {
            Module::where('identifier', self::TEMP_MODULE)->delete();
            foreach ($originalStatuses as $identifier => $status) {
                Module::where('identifier', $identifier)->update(['status' => $status]);
            }
            ModuleManager::invalidateModuleStatusCache();
        }
    }

    /**
     * 빈 결과는 캐시에 남지 않아야 합니다 (플러그인).
     */
    #[Test]
    public function it_does_not_cache_empty_active_plugins(): void
    {
        $originalStatuses = Plugin::query()->pluck('status', 'identifier')->all();
        Plugin::where('identifier', self::TEMP_PLUGIN)->delete();

        try {
            $plugin = Plugin::factory()->create([
                'identifier' => self::TEMP_PLUGIN,
                'status' => ExtensionStatus::Inactive->value,
            ]);
            Plugin::query()->update(['status' => ExtensionStatus::Inactive->value]);
            PluginManager::invalidatePluginStatusCache();

            $this->assertSame([], PluginManager::getActivePluginIdentifiers());
            $this->assertNull(Cache::get(self::PLUGIN_ACTIVE_KEY));

            $plugin->update(['status' => ExtensionStatus::Active->value]);

            $this->assertContains(self::TEMP_PLUGIN, PluginManager::getActivePluginIdentifiers());
        } finally {
            Plugin::where('identifier', self::TEMP_PLUGIN)->delete();
            foreach ($originalStatuses as $identifier => $status) {
                Plugin::where('identifier', $identifier)->update(['status' => $status]);
            }
            PluginManager::invalidatePluginStatusCache();
        }
    }
}
