<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Extension\Cache\CoreCacheDriver;
use Tests\TestCase;

/**
 * 코어 캐시·스토리지 글로벌 바인딩 무결성 회귀 가드.
 *
 * 이슈 #392 — 어떤 플러그인 ServiceProvider 도 `app(CacheInterface)` 글로벌 바인딩을
 * PluginCacheDriver 로 덮어쓰면 안 된다. 글로벌 바인딩이 덮어쓰여지면 코어
 * LayoutService 등의 `$this->cache->put('ext.cache_version', ...)` 가 플러그인
 * 도메인으로 누수되어 화면에 반영되지 않는 결함이 재발한다.
 *
 * 본 테스트는 코어 컨텍스트에서 모든 ServiceProvider 가 부팅된 직후의 상태를 검증한다.
 * 플러그인별 contextual binding 정확성 (식별자 prefix 매칭 등) 은 해당 플러그인의
 * Feature 테스트로 검증한다 (예: PluginCacheContextualBindingTest in
 * plugins/_bundled/sirsoft-verification_kginicis/tests/Feature/).
 *
 * @see tests/scenarios/extension-cache-binding-isolation.yaml
 */
class PluginCacheBindingIsolationTest extends TestCase
{
    /**
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=single_class
     * @effects global_cache_interface_binding_remains_core_cache_driver
     */
    public function test_global_cache_interface_resolves_to_core_cache_driver(): void
    {
        $cache = $this->app->make(CacheInterface::class);

        $this->assertInstanceOf(
            CoreCacheDriver::class,
            $cache,
            'app(CacheInterface) 는 항상 CoreCacheDriver 여야 한다. '
            .'PluginCacheDriver 가 반환되면 어떤 ServiceProvider 가 '
            .'$this->app->singleton(CacheInterface::class, ...) 로 글로벌 바인딩을 덮어쓰고 있다 (이슈 #392).'
        );
    }

    /**
     * @scenario extension_kind=plugin,service_kind=storage_service,global_binding_state=absent,manager_state=plugin_resolved,consumer_type=no_registration
     * @effects global_storage_interface_binding_remains_core_storage_driver
     */
    public function test_global_storage_interface_is_not_bound_to_extension_driver(): void
    {
        // 코어는 StorageInterface 를 글로벌 바인딩하지 않는다 — 확장은 contextual binding
        // 으로만 StorageInterface 를 주입해야 한다. 어떤 ServiceProvider 가 글로벌
        // 바인딩을 등록했다면 그 자체가 결함 후보 (특히 확장 도메인 드라이버로 바인딩 시
        // 코어/타 확장 도메인의 파일 접근이 누수됨).
        if (! $this->app->bound(StorageInterface::class)) {
            $this->assertTrue(true, 'StorageInterface 는 글로벌 바인딩이 없는 상태가 정상.');

            return;
        }

        $storage = $this->app->make(StorageInterface::class);

        $this->assertNotInstanceOf(
            \App\Extension\Storage\PluginStorageDriver::class,
            $storage,
            'app(StorageInterface) 는 PluginStorageDriver 가 아니어야 한다 — 확장 ServiceProvider 의 글로벌 재바인딩 의심.'
        );
        $this->assertNotInstanceOf(
            \App\Extension\Storage\ModuleStorageDriver::class,
            $storage,
            'app(StorageInterface) 는 ModuleStorageDriver 가 아니어야 한다 — 확장 ServiceProvider 의 글로벌 재바인딩 의심.'
        );
    }

    /**
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=single_class
     * @effects layout_service_ext_cache_version_key_written_to_core_namespace
     */
    public function test_core_cache_writes_use_core_namespace_prefix(): void
    {
        $cache = $this->app->make(CacheInterface::class);
        $this->assertInstanceOf(CoreCacheDriver::class, $cache);

        $resolvedKey = $cache->resolveKey('ext.cache_version');

        $this->assertStringStartsWith(
            'g7:core:',
            $resolvedKey,
            '코어 LayoutService 의 ext.cache_version bump 가 플러그인 도메인 (g7:plugin.*:) 으로 누수되면 안 된다.'
        );

        // 실제 put → has → forget 사이클로 코어 캐시 도메인이 정상 동작함을 검증
        $cache->put('isolation_probe_392', 'probe', 60);
        $this->assertTrue($cache->has('isolation_probe_392'));
        $cache->forget('isolation_probe_392');
    }
}
