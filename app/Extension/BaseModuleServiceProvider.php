<?php

namespace App\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * 모듈 서비스 프로바이더 베이스 클래스
 *
 * 모든 모듈의 ServiceProvider가 상속받는 추상 클래스입니다.
 * 공통 기능을 자동화하여 코드 중복을 제거하고 일관성을 확보합니다.
 */
abstract class BaseModuleServiceProvider extends ServiceProvider
{
    /**
     * 모듈 식별자 (자식 클래스에서 반드시 정의)
     *
     * @var string
     */
    protected string $moduleIdentifier;

    /**
     * StorageInterface가 필요한 서비스 클래스 목록
     *
     * 이 배열에 정의된 서비스들은 자동으로 StorageInterface가 주입됩니다.
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [];

    /**
     * CacheInterface가 필요한 서비스 클래스 목록
     *
     * 이 배열에 정의된 서비스들은 자동으로 CacheInterface가 주입됩니다.
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [];

    /**
     * Repository 인터페이스와 구현체 매핑
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [];

    /**
     * ServiceProvider 파일이 위치한 디렉토리 경로 (캐시)
     *
     * @var string|null
     */
    private ?string $providerPath = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        // Repository 바인딩
        $this->registerRepositories();

        // StorageInterface 바인딩
        $this->registerStorageBindings();

        // CacheInterface 바인딩
        $this->registerCacheBindings();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // 마이그레이션 자동 로드
        $this->loadModuleMigrations();

        // 다국어 자동 로드
        $this->loadModuleTranslations();
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
     * StorageInterface를 필요로 하는 서비스에 자동 바인딩합니다.
     *
     * 각 서비스의 생성자에서 StorageInterface를 주입받으면,
     * 해당 모듈의 Storage 인스턴스가 자동으로 주입됩니다.
     */
    protected function registerStorageBindings(): void
    {
        if (empty($this->storageServices)) {
            return;
        }

        $this->app->when($this->storageServices)
            ->needs(StorageInterface::class)
            ->give(function () {
                return $this->app->make(ModuleManager::class)
                    ->getModule($this->moduleIdentifier)
                    ->getStorage();
            });
    }

    /**
     * CacheInterface를 필요로 하는 서비스에 자동 바인딩합니다.
     *
     * 각 서비스의 생성자에서 CacheInterface를 주입받으면,
     * 해당 모듈의 Cache 인스턴스가 자동으로 주입됩니다.
     */
    protected function registerCacheBindings(): void
    {
        if (empty($this->cacheServices)) {
            return;
        }

        $this->app->when($this->cacheServices)
            ->needs(CacheInterface::class)
            ->give(function () {
                return $this->app->make(ModuleManager::class)
                    ->getModule($this->moduleIdentifier)
                    ->getCache();
            });
    }

    /**
     * ServiceProvider 파일의 디렉토리 경로 반환
     *
     * ReflectionClass를 사용하여 자식 클래스의 실제 경로를 반환합니다.
     * __DIR__은 베이스 클래스 경로를 반환하므로 사용할 수 없습니다.
     *
     * @return string ServiceProvider 디렉토리 경로 (예: modules/vendor-module/src/Providers)
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
     * 모듈의 마이그레이션 파일을 로드합니다.
     *
     * 기본 경로: {module}/database/migrations
     * 자식 클래스는 {module}/src/Providers에 위치해야 합니다.
     *
     * 참고: 모듈 마이그레이션은 php artisan migrate와 분리됩니다.
     * module:install, module:activate 명령어에서 ModuleManager::runMigrations()로 실행됩니다.
     */
    protected function loadModuleMigrations(): void
    {
        // 모듈 마이그레이션은 loadMigrationsFrom()으로 등록하지 않음
        // 대신 ModuleManager::runMigrations()에서 별도로 실행됨
    }

    /**
     * 모듈의 다국어 파일을 로드합니다.
     *
     * 기본 경로: {module}/src/lang
     * 자식 클래스는 {module}/src/Providers에 위치해야 합니다.
     */
    protected function loadModuleTranslations(): void
    {
        $langPath = $this->getProviderPath().'/../lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleIdentifier);
        }
    }
}
