<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheableExtensionInterface;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\BasePluginServiceProvider;
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
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=absent,manager_state=plugin_null_fallback,consumer_type=single_class
     * @effects inline_adapter_used_when_plugin_not_registered_in_manager
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

class TestBasePluginServiceProviderFixture extends BasePluginServiceProvider
{
    public function __construct($app, string $identifier)
    {
        parent::__construct($app);
        $this->pluginIdentifier = $identifier;
    }

    public function setCacheServices(array $services): void
    {
        $this->cacheServices = $services;
    }
}
