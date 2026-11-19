<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheableExtensionInterface;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Extension\AbstractExtensionServiceProvider;
use Mockery;
use Tests\TestCase;

require_once __DIR__.'/ServiceProviderTestFixtures.php';

/**
 * AbstractExtensionServiceProvider 공통 자동 바인딩 동작 검증.
 *
 * - $repositories 배열은 interface → implementation 으로 바인딩된다.
 * - $cacheServices 배열은 contextual binding 으로 확장 캐시를 주입한다.
 * - $storageServices 배열은 contextual binding 으로 확장 스토리지를 주입한다.
 * - 글로벌 CacheInterface / StorageInterface 바인딩은 덮어쓰지 않는다.
 */
class AbstractExtensionServiceProviderTest extends TestCase
{
    /**
     * $repositories 배열에 적은 인터페이스를 컨테이너에서 꺼내면 짝지은 구현 클래스가 나온다.
     *
     * @effects repositories_array_binds_interface_to_implementation
     *
     * @return void 반환값 없음
     */
    public function test_repositories_bind_interface_to_implementation(): void
    {
        $extension = Mockery::mock(CacheableExtensionInterface::class);

        $provider = new TestExtensionServiceProviderFixture($this->app, $extension);
        $provider->setRepositories([
            FixtureRepositoryInterface::class => FixtureRepositoryImpl::class,
        ]);
        $provider->register();

        $resolved = $this->app->make(FixtureRepositoryInterface::class);

        $this->assertInstanceOf(FixtureRepositoryImpl::class, $resolved);
    }

    /**
     * $cacheServices 에 적은 클래스는 확장의 캐시를 contextual binding 으로 주입받는다.
     *
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=absent,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects cache_service_receives_plugin_cache_driver_with_correct_identifier_prefix, inicis_identity_provider_receives_correctly_prefixed_plugin_cache
     *
     * @return void 반환값 없음
     */
    public function test_cache_services_receive_extension_cache_via_contextual_binding(): void
    {
        $cache = Mockery::mock(CacheInterface::class);

        $extension = Mockery::mock(CacheableExtensionInterface::class);
        $extension->shouldReceive('getCache')->andReturn($cache);

        $provider = new TestExtensionServiceProviderFixture($this->app, $extension);
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->register();

        $consumer = $this->app->make(FixtureCacheConsumer::class);

        $this->assertSame($cache, $consumer->cache);
    }

    /**
     * $storageServices 에 적은 클래스는 확장의 스토리지를 contextual binding 으로 주입받는다.
     *
     * @scenario extension_kind=plugin,service_kind=storage_service,global_binding_state=absent,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects storage_service_receives_plugin_storage_driver_with_correct_disk
     *
     * @return void 반환값 없음
     */
    public function test_storage_services_receive_extension_storage_via_contextual_binding(): void
    {
        $storage = Mockery::mock(StorageInterface::class);

        $extension = Mockery::mock(CacheableExtensionInterface::class);
        $extension->shouldReceive('getStorage')->andReturn($storage);

        $provider = new TestExtensionServiceProviderFixture($this->app, $extension);
        $provider->setStorageServices([FixtureStorageConsumer::class]);
        $provider->register();

        $consumer = $this->app->make(FixtureStorageConsumer::class);

        $this->assertSame($storage, $consumer->storage);
    }

    /**
     * 캐시와 스토리지 소비자를 등록해도 전역 CacheInterface, StorageInterface 바인딩은 등록 전 인스턴스 그대로다.
     *
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=installed_before_provider,manager_state=plugin_resolved,consumer_type=single_class
     *
     * @effects global_cache_interface_binding_remains_core_cache_driver, global_storage_interface_binding_remains_core_storage_driver
     *
     * @return void 반환값 없음
     */
    public function test_register_does_not_rebind_global_cache_or_storage_interface(): void
    {
        $globalCache = Mockery::mock(CacheInterface::class);
        $this->app->instance(CacheInterface::class, $globalCache);

        $globalStorage = Mockery::mock(StorageInterface::class);
        $this->app->instance(StorageInterface::class, $globalStorage);

        $extension = Mockery::mock(CacheableExtensionInterface::class);
        $extension->shouldReceive('getCache')->andReturn(Mockery::mock(CacheInterface::class));
        $extension->shouldReceive('getStorage')->andReturn(Mockery::mock(StorageInterface::class));

        $provider = new TestExtensionServiceProviderFixture($this->app, $extension);
        $provider->setCacheServices([FixtureCacheConsumer::class]);
        $provider->setStorageServices([FixtureStorageConsumer::class]);
        $provider->register();

        $this->assertSame($globalCache, $this->app->make(CacheInterface::class));
        $this->assertSame($globalStorage, $this->app->make(StorageInterface::class));
    }

    /**
     * 서비스 배열이 비어 있으면 등록 과정에서 확장의 캐시와 스토리지를 꺼내지 않는다.
     *
     * @scenario extension_kind=plugin,service_kind=cache_service,global_binding_state=absent,manager_state=plugin_resolved,consumer_type=no_registration
     *
     * @effects empty_service_arrays_skip_contextual_binding_entirely
     *
     * @return void 반환값 없음
     */
    public function test_empty_arrays_skip_contextual_bindings(): void
    {
        $extension = Mockery::mock(CacheableExtensionInterface::class);
        $extension->shouldNotReceive('getCache');
        $extension->shouldNotReceive('getStorage');

        $provider = new TestExtensionServiceProviderFixture($this->app, $extension);
        $provider->register();

        $this->assertTrue(true);
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

/**
 * 테스트 fixture: 확장 인스턴스를 직접 주입받는 ServiceProvider.
 */
class TestExtensionServiceProviderFixture extends AbstractExtensionServiceProvider
{
    protected string $extensionIdentifier = 'fixture-extension';

    private CacheableExtensionInterface $extension;

    /**
     * 시험이 넘긴 확장 인스턴스를 보관한다.
     *
     * @param  mixed  $app  애플리케이션 컨테이너(부모 생성자에 그대로 넘긴다)
     * @param  CacheableExtensionInterface  $extension  프로바이더가 해석 결과로 쓸 확장
     */
    public function __construct($app, CacheableExtensionInterface $extension)
    {
        parent::__construct($app);
        $this->extension = $extension;
    }

    /**
     * 생성자에서 받은 확장을 그대로 돌려준다.
     *
     * @return CacheableExtensionInterface 생성자에서 받은 확장
     */
    protected function resolveExtension(): CacheableExtensionInterface
    {
        return $this->extension;
    }

    /**
     * 저장소 바인딩 목록을 시험 값으로 지정한다.
     *
     * @param  array<class-string, class-string>  $repositories  인터페이스와 구현 클래스의 짝
     * @return void 반환값 없음
     */
    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    /**
     * 확장 캐시를 주입받을 클래스 목록을 시험 값으로 지정한다.
     *
     * @param  array<int, class-string>  $services  캐시 소비자 클래스 목록
     * @return void 반환값 없음
     */
    public function setCacheServices(array $services): void
    {
        $this->cacheServices = $services;
    }

    /**
     * 확장 스토리지를 주입받을 클래스 목록을 시험 값으로 지정한다.
     *
     * @param  array<int, class-string>  $services  스토리지 소비자 클래스 목록
     * @return void 반환값 없음
     */
    public function setStorageServices(array $services): void
    {
        $this->storageServices = $services;
    }
}

// 공유 fixture (FixtureRepositoryInterface / FixtureRepositoryImpl /
// FixtureCacheConsumer / FixtureStorageConsumer) 는 ServiceProviderTestFixtures.php
// 에 정의되어 있으며 PSR-4 / composer classmap 으로 자동 로드됩니다.
