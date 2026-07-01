<?php

namespace App\Extension;

use App\Contracts\Extension\CacheableExtensionInterface;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * 확장(모듈/플러그인) 서비스 프로바이더 공통 베이스.
 *
 * 모듈·플러그인이 공유하는 자동 바인딩 표면(Repository, Storage, Cache)과
 * 다국어/마이그레이션 로드 hook 을 단일 클래스에 집약합니다. 자식 베이스
 * (BaseModuleServiceProvider / BasePluginServiceProvider) 는 확장 인스턴스
 * 해석 방식만 결정합니다.
 *
 * 글로벌 컨테이너 바인딩(`$this->app->singleton(CacheInterface::class, ...)`)
 * 으로 코어 도메인을 덮어쓰는 패턴은 금지됩니다. cacheServices 배열에 클래스만
 * 등록하면 Laravel contextual binding 으로 해당 확장 도메인의 캐시 인스턴스가
 * 자동 주입됩니다.
 */
abstract class AbstractExtensionServiceProvider extends ServiceProvider
{
    /**
     * 확장 식별자 (vendor-extension). 자식 클래스에서 반드시 정의.
     *
     * @var string
     */
    protected string $extensionIdentifier;

    /**
     * StorageInterface 가 필요한 서비스 클래스 목록.
     *
     * 등록된 클래스의 생성자에서 StorageInterface 를 의존하면, 해당 확장
     * 도메인의 Storage 인스턴스가 contextual binding 으로 자동 주입됩니다.
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [];

    /**
     * CacheInterface 가 필요한 서비스 클래스 목록.
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [];

    /**
     * Repository 인터페이스 ↔ 구현체 매핑.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [];

    /**
     * ServiceProvider 파일이 위치한 디렉토리 경로 (캐시).
     */
    private ?string $providerPath = null;

    /**
     * 확장 인스턴스를 해석합니다.
     *
     * BaseModuleServiceProvider 는 ModuleManager, BasePluginServiceProvider 는
     * PluginManager 를 통해 해당 식별자의 확장을 가져옵니다.
     *
     * @return CacheableExtensionInterface 캐시/스토리지 도메인을 보유한 확장
     */
    abstract protected function resolveExtension(): CacheableExtensionInterface;

    /**
     * 다국어 도메인 이름. 기본값은 확장 식별자이며 자식이 필요 시 오버라이드.
     */
    protected function translationNamespace(): string
    {
        return $this->extensionIdentifier;
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerRepositories();
        $this->registerStorageBindings();
        $this->registerCacheBindings();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadExtensionMigrations();
        $this->loadExtensionTranslations();
    }

    /**
     * Repository 인터페이스를 구현체에 바인딩합니다.
     */
    protected function registerRepositories(): void
    {
        foreach ($this->repositories as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }

    /**
     * StorageInterface 를 필요로 하는 서비스에 contextual binding 으로 주입합니다.
     */
    protected function registerStorageBindings(): void
    {
        if (empty($this->storageServices)) {
            return;
        }

        $this->app->when($this->storageServices)
            ->needs(StorageInterface::class)
            ->give(fn () => $this->resolveExtension()->getStorage());
    }

    /**
     * CacheInterface 를 필요로 하는 서비스에 contextual binding 으로 주입합니다.
     */
    protected function registerCacheBindings(): void
    {
        if (empty($this->cacheServices)) {
            return;
        }

        $this->app->when($this->cacheServices)
            ->needs(CacheInterface::class)
            ->give(fn () => $this->resolveExtension()->getCache());
    }

    /**
     * ServiceProvider 파일의 디렉토리 경로를 반환합니다.
     *
     * ReflectionClass 로 자식 클래스의 실제 경로를 가져옵니다 (__DIR__ 은 베이스
     * 경로를 반환하므로 사용 불가).
     */
    protected function getProviderPath(): string
    {
        if ($this->providerPath === null) {
            $reflection = new ReflectionClass($this);
            $this->providerPath = dirname($reflection->getFileName());
        }

        return $this->providerPath;
    }

    /**
     * 확장 마이그레이션 로드 hook (기본 no-op).
     *
     * 확장 마이그레이션은 ModuleManager/PluginManager::runMigrations() 가 별도로
     * 실행하므로 여기서는 loadMigrationsFrom() 을 호출하지 않습니다.
     */
    protected function loadExtensionMigrations(): void
    {
        // no-op: ModuleManager/PluginManager::runMigrations() 에서 처리됨
    }

    /**
     * 확장의 다국어 파일을 로드합니다.
     *
     * 기본 경로: ServiceProvider 디렉토리에서 상위로 한 단계 올라간 lang/ 디렉토리.
     */
    protected function loadExtensionTranslations(): void
    {
        $langPath = $this->getProviderPath().'/../lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->translationNamespace());
        }
    }
}
