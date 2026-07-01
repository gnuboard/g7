<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheableExtensionInterface;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ModuleInterface;
use App\Extension\BaseModuleServiceProvider;
use App\Extension\ModuleManager;
use Mockery;
use Tests\TestCase;

require_once __DIR__.'/ServiceProviderTestFixtures.php';

/**
 * BaseModuleServiceProvider 단위 테스트 — 부모 추출 후 BC 보장.
 *
 * 기존 자식 클래스는 `$moduleIdentifier` 속성만 정의하며, 부모의
 * `$extensionIdentifier` 로 투명하게 미러링되어야 한다.
 */
class BaseModuleServiceProviderTest extends TestCase
{
    public function test_module_identifier_mirrors_to_extension_identifier(): void
    {
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getCache')
            ->andReturn($moduleCache = Mockery::mock(CacheInterface::class));

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getModule')
            ->with('vendor-test_module')
            ->andReturn($module);

        $this->app->instance(ModuleManager::class, $moduleManager);

        $provider = new TestBaseModuleServiceProviderFixture($this->app, 'vendor-test_module');
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->register();

        $consumer = $this->app->make(FixtureCacheConsumer::class);

        $this->assertSame($moduleCache, $consumer->cache);
    }

    public function test_register_does_not_rebind_global_cache_interface(): void
    {
        $globalCache = Mockery::mock(CacheInterface::class);
        $this->app->instance(CacheInterface::class, $globalCache);

        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getCache')->andReturn(Mockery::mock(CacheInterface::class));

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getModule')->andReturn($module);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $provider = new TestBaseModuleServiceProviderFixture($this->app, 'vendor-test_module');
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->register();

        $this->assertSame($globalCache, $this->app->make(CacheInterface::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

class TestBaseModuleServiceProviderFixture extends BaseModuleServiceProvider
{
    public function __construct($app, string $identifier)
    {
        parent::__construct($app);
        $this->moduleIdentifier = $identifier;
    }

    public function setCacheServices(array $services): void
    {
        $this->cacheServices = $services;
    }
}
