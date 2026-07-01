<?php

namespace App\Extension;

use App\Contracts\Extension\CacheableExtensionInterface;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Extension\Cache\ModuleCacheDriver;
use App\Extension\Storage\ModuleStorageDriver;

/**
 * 모듈 서비스 프로바이더 베이스 클래스.
 *
 * 공통 자동 바인딩 로직은 AbstractExtensionServiceProvider 가 보유하며,
 * 본 클래스는 ModuleManager 를 통한 확장 해석만 담당합니다. 기존 자식
 * 클래스는 `$moduleIdentifier` 속성을 그대로 사용할 수 있으며, 부모의
 * `$extensionIdentifier` 에 자동 미러링됩니다.
 */
abstract class BaseModuleServiceProvider extends AbstractExtensionServiceProvider
{
    /**
     * 모듈 식별자 (하위 호환 alias).
     *
     * 기존 자식 클래스가 이 속성으로 지정한 값을 부모의
     * `$extensionIdentifier` 로 미러링합니다.
     */
    protected string $moduleIdentifier;

    /**
     * 모듈 인스턴스를 해석합니다.
     *
     * 정상 경로: ModuleManager 가 활성 모듈 인스턴스를 반환합니다.
     *
     * Fallback: 테스트 격리 / 모듈 디스커버리 이전 시점에서는 ModuleCacheDriver /
     * ModuleStorageDriver 를 식별자만으로 직접 생성한 어댑터를 반환합니다.
     */
    protected function resolveExtension(): CacheableExtensionInterface
    {
        $module = $this->app->make(ModuleManager::class)
            ->getModule($this->extensionIdentifier);

        if ($module !== null) {
            return $module;
        }

        return new InlineModuleExtensionAdapter($this->extensionIdentifier);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->ensureIdentifierAlias();
        parent::register();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->ensureIdentifierAlias();
        parent::boot();
    }

    /**
     * `$moduleIdentifier` 값을 부모의 `$extensionIdentifier` 로 미러링합니다.
     */
    private function ensureIdentifierAlias(): void
    {
        if (isset($this->moduleIdentifier) && ! isset($this->extensionIdentifier)) {
            $this->extensionIdentifier = $this->moduleIdentifier;
        }
    }

    /**
     * @deprecated 7.0.0-beta.7 부모 `loadExtensionMigrations()` 사용 권장.
     */
    protected function loadModuleMigrations(): void
    {
        $this->loadExtensionMigrations();
    }

    /**
     * @deprecated 7.0.0-beta.7 부모 `loadExtensionTranslations()` 사용 권장.
     */
    protected function loadModuleTranslations(): void
    {
        $this->loadExtensionTranslations();
    }
}

/**
 * ModuleManager 가 식별자 매핑을 보유하지 않는 컨텍스트에서 사용되는 어댑터.
 *
 * AbstractModule::getCache() / getStorage() 와 동일한 키 prefix·디스크 정책을
 * 따르도록 ModuleCacheDriver / ModuleStorageDriver 를 직접 생성합니다.
 *
 * @internal BaseModuleServiceProvider 의 fallback 전용.
 */
final class InlineModuleExtensionAdapter implements CacheableExtensionInterface
{
    private ?CacheInterface $cache = null;

    private ?StorageInterface $storage = null;

    /**
     * @param  string  $identifier  모듈 식별자 (vendor-module)
     */
    public function __construct(private readonly string $identifier) {}

    /**
     * 모듈 식별자를 반환합니다.
     *
     * @return string 모듈 식별자
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * 모듈 도메인 캐시 드라이버를 반환합니다.
     *
     * @return CacheInterface ModuleCacheDriver 인스턴스
     */
    public function getCache(): CacheInterface
    {
        return $this->cache ??= new ModuleCacheDriver($this->identifier);
    }

    /**
     * 모듈 도메인 스토리지 드라이버를 반환합니다.
     *
     * @return StorageInterface ModuleStorageDriver 인스턴스
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage ??= new ModuleStorageDriver($this->identifier);
    }
}
