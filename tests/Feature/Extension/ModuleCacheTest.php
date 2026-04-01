<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 모듈/플러그인 상태 캐시 통합 테스트
 *
 * 빈 배열 캐싱 금지 기능이 모듈/플러그인 활성 목록 조회에
 * 올바르게 적용되는지 검증합니다.
 */
class ModuleCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 캐시 초기화
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
     * 활성 모듈이 없을 때 빈 배열이 캐시되지 않아야 합니다.
     */
    #[Test]
    public function it_does_not_cache_empty_active_modules(): void
    {
        // 기존 상태 백업
        $originalStatuses = Module::pluck('status', 'id')->toArray();

        try {
            // 모든 모듈 비활성화
            Module::query()->update(['status' => ExtensionStatus::Inactive->value]);

            // 캐시 무효화
            ModuleManager::invalidateModuleStatusCache();

            // 첫 번째 조회
            $result1 = ModuleManager::getActiveModuleIdentifiers();
            $this->assertEquals([], $result1);

            // 캐시에 저장되지 않았는지 확인
            $cacheKey = CacheService::key('modules', 'active_identifiers');
            $this->assertNull(Cache::get($cacheKey), '빈 배열이 캐시에 저장되면 안됩니다');

            // 두 번째 조회도 동일하게 빈 배열 반환
            $result2 = ModuleManager::getActiveModuleIdentifiers();
            $this->assertEquals([], $result2);
        } finally {
            // 원래 상태 복원
            foreach ($originalStatuses as $id => $status) {
                Module::where('id', $id)->update(['status' => $status]);
            }
        }
    }

    /**
     * 활성 모듈이 있을 때 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_non_empty_active_modules(): void
    {
        // 최소 1개 활성 모듈 확인
        $activeModule = Module::where('status', ExtensionStatus::Active->value)->first();
        if (! $activeModule) {
            $this->markTestSkipped('활성 모듈이 없습니다.');
        }

        // 캐시 무효화 후 조회
        ModuleManager::invalidateModuleStatusCache();
        $result = ModuleManager::getActiveModuleIdentifiers();

        $this->assertNotEmpty($result, '활성 모듈 목록이 비어있으면 안됩니다');

        // 캐시에 저장되었는지 확인
        $cacheKey = CacheService::key('modules', 'active_identifiers');
        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached, '활성 모듈 목록이 캐시되어야 합니다');
        $this->assertEquals($result, $cached);
    }

    /**
     * 모듈 상태 변경 후 캐시 무효화가 정상 동작해야 합니다.
     */
    #[Test]
    public function it_invalidates_cache_on_status_change(): void
    {
        // 초기 조회 (캐시 생성)
        $initialResult = ModuleManager::getActiveModuleIdentifiers();

        if (empty($initialResult)) {
            $this->markTestSkipped('활성 모듈이 없습니다.');
        }

        $cacheKey = CacheService::key('modules', 'active_identifiers');

        // 캐시 확인
        $this->assertNotNull(Cache::get($cacheKey), '초기 캐시가 생성되어야 합니다');

        // 캐시 무효화
        ModuleManager::invalidateModuleStatusCache();

        // 캐시 삭제 확인
        $this->assertNull(Cache::get($cacheKey), '캐시가 무효화되어야 합니다');
    }

    /**
     * 활성 플러그인이 없을 때 빈 배열이 캐시되지 않아야 합니다.
     */
    #[Test]
    public function it_does_not_cache_empty_active_plugins(): void
    {
        // 기존 상태 백업
        $originalStatuses = Plugin::pluck('status', 'id')->toArray();

        try {
            // 모든 플러그인 비활성화
            Plugin::query()->update(['status' => ExtensionStatus::Inactive->value]);

            // 캐시 무효화
            PluginManager::invalidatePluginStatusCache();

            // 조회
            $result = PluginManager::getActivePluginIdentifiers();
            $this->assertEquals([], $result);

            // 캐시에 저장되지 않았는지 확인
            $cacheKey = CacheService::key('plugins', 'active_identifiers');
            $this->assertNull(Cache::get($cacheKey), '빈 배열이 캐시에 저장되면 안됩니다');
        } finally {
            // 원래 상태 복원
            foreach ($originalStatuses as $id => $status) {
                Plugin::where('id', $id)->update(['status' => $status]);
            }
        }
    }

    /**
     * 활성 플러그인이 있을 때 정상적으로 캐시되어야 합니다.
     */
    #[Test]
    public function it_caches_non_empty_active_plugins(): void
    {
        // 최소 1개 활성 플러그인 확인
        $activePlugin = Plugin::where('status', ExtensionStatus::Active->value)->first();
        if (! $activePlugin) {
            $this->markTestSkipped('활성 플러그인이 없습니다.');
        }

        // 캐시 무효화 후 조회
        PluginManager::invalidatePluginStatusCache();
        $result = PluginManager::getActivePluginIdentifiers();

        $this->assertNotEmpty($result, '활성 플러그인 목록이 비어있으면 안됩니다');

        // 캐시에 저장되었는지 확인
        $cacheKey = CacheService::key('plugins', 'active_identifiers');
        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached, '활성 플러그인 목록이 캐시되어야 합니다');
        $this->assertEquals($result, $cached);
    }
}
