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
 * 참고: 이전의 "빈 배열 캐시 금지" 정책은 새 시스템에서 폐기되었습니다.
 * 대신 모듈 설치/활성화 훅에서 invalidateModuleStatusCache() 로 확실히 무효화합니다.
 */
class ModuleCacheTest extends TestCase
{
    private const MODULE_ACTIVE_KEY = 'g7:core:ext.modules.active_identifiers';

    private const PLUGIN_ACTIVE_KEY = 'g7:core:ext.plugins.active_identifiers';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
    }

    protected function tearDown(): void
    {
        Cache::flush();
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
}
