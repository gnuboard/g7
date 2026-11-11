<?php

namespace Tests\Unit\Extension;

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
    /**
     * $moduleIdentifier 만 정한 프로바이더가 ModuleManager 에서 그 식별자의 모듈을 찾아 모듈 캐시를 소비자에 주입한다.
     *
     * @scenario extension_kind=module,service_kind=cache_service,global_binding_state=absent,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects module_identifier_mirrors_to_extension_identifier_for_cache_binding
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈 프로바이더가 캐시 소비자를 등록해도 전역 CacheInterface 바인딩은 등록 전 인스턴스 그대로다.
     *
     * @scenario extension_kind=module,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects global_cache_interface_binding_remains_core_cache_driver
     *
     * @return void 반환값 없음
     */
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

class TestBaseModuleServiceProviderFixture extends BaseModuleServiceProvider
{
    /**
     * 시험이 넘긴 식별자를 모듈 식별자로 지정한다.
     *
     * @param  mixed  $app  애플리케이션 컨테이너(부모 생성자에 그대로 넘긴다)
     * @param  string  $identifier  모듈 식별자
     */
    public function __construct($app, string $identifier)
    {
        parent::__construct($app);
        $this->moduleIdentifier = $identifier;
    }

    /**
     * 모듈 캐시를 주입받을 클래스 목록을 시험 값으로 지정한다.
     *
     * @param  array<int, class-string>  $services  캐시 소비자 클래스 목록
     * @return void 반환값 없음
     */
    public function setCacheServices(array $services): void
    {
        $this->cacheServices = $services;
    }
}
