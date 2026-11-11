<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\BasePluginServiceProvider;
use App\Extension\Cache\PluginCacheDriver;
use App\Extension\PluginManager;
use Mockery;
use Tests\TestCase;

require_once __DIR__.'/ServiceProviderTestFixtures.php';

/**
 * BasePluginServiceProvider 단위 테스트.
 *
 * - $pluginIdentifier 가 부모의 $extensionIdentifier 로 미러링된다.
 * - PluginManager 를 통해 확장을 해석하고 contextual binding 으로 캐시를 주입한다.
 * - 글로벌 CacheInterface 바인딩은 덮어쓰지 않는다 (핵심 결함 차단).
 */
class BasePluginServiceProviderTest extends TestCase
{
    /**
     * $pluginIdentifier 만 정한 프로바이더가 PluginManager 에서 그 식별자의 플러그인을 찾아 플러그인 캐시를 소비자에 주입한다.
     *
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=absent,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects cache_service_receives_plugin_cache_driver_with_correct_identifier_prefix
     *
     * @return void 반환값 없음
     */
    public function test_plugin_identifier_mirrors_to_extension_identifier(): void
    {
        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getCache')
            ->andReturn($pluginCache = Mockery::mock(CacheInterface::class));

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getPlugin')
            ->with('vendor-test_plugin')
            ->andReturn($plugin);

        $this->app->instance(PluginManager::class, $pluginManager);

        $provider = new TestBasePluginServiceProviderFixture($this->app, 'vendor-test_plugin');
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->register();

        $consumer = $this->app->make(FixtureCacheConsumer::class);

        $this->assertSame($pluginCache, $consumer->cache);
    }

    /**
     * PluginManager 가 그 식별자의 플러그인을 돌려주지 못하면(등록 전 해석 등) 식별자만으로 만든 플러그인 캐시 드라이버를 소비자에 주입한다.
     *
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=absent,manager_state=plugin_null_fallback,consumer_type=single_class
     *
     * @effects inline_adapter_used_when_plugin_not_registered_in_manager
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_identifier_only_cache_driver_when_plugin_is_not_registered(): void
    {
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getPlugin')
            ->with('vendor-test_plugin')
            ->andReturn(null);

        $this->app->instance(PluginManager::class, $pluginManager);

        $provider = new TestBasePluginServiceProviderFixture($this->app, 'vendor-test_plugin');
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->register();

        $consumer = $this->app->make(FixtureCacheConsumer::class);

        $this->assertInstanceOf(PluginCacheDriver::class, $consumer->cache);
    }

    /**
     * 플러그인 프로바이더가 캐시 소비자를 등록해도 전역 CacheInterface 바인딩은 등록 전 인스턴스 그대로다.
     *
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects global_cache_interface_binding_remains_core_cache_driver
     *
     * @return void 반환값 없음
     */
    public function test_register_does_not_rebind_global_cache_interface(): void
    {
        $globalCache = Mockery::mock(CacheInterface::class);
        $this->app->instance(CacheInterface::class, $globalCache);

        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getCache')->andReturn(Mockery::mock(CacheInterface::class));

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getPlugin')->andReturn($plugin);
        $this->app->instance(PluginManager::class, $pluginManager);

        $provider = new TestBasePluginServiceProviderFixture($this->app, 'vendor-test_plugin');
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->register();

        // 글로벌 바인딩이 보존되어 코어 도메인이 누수되지 않음을 검증
        $this->assertSame($globalCache, $this->app->make(CacheInterface::class));
    }

    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }
}

class TestBasePluginServiceProviderFixture extends BasePluginServiceProvider
{
    /**
     * 시험이 넘긴 식별자를 플러그인 식별자로 지정한다.
     *
     * @param  mixed  $app  애플리케이션 컨테이너(부모 생성자에 그대로 넘긴다)
     * @param  string  $identifier  플러그인 식별자
     */
    public function __construct($app, string $identifier)
    {
        parent::__construct($app);
        $this->pluginIdentifier = $identifier;
    }

    /**
     * 플러그인 캐시를 주입받을 클래스 목록을 시험 값으로 지정한다.
     *
     * @param  array<int, class-string>  $services  캐시 소비자 클래스 목록
     * @return void 반환값 없음
     */
    public function setCacheServices(array $services): void
    {
        $this->cacheServices = $services;
    }
}
