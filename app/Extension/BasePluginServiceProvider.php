<?php

namespace App\Extension;

use App\Contracts\Extension\CacheableExtensionInterface;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Extension\Cache\PluginCacheDriver;
use App\Extension\Storage\PluginStorageDriver;

/**
 * 플러그인 서비스 프로바이더 베이스 클래스.
 *
 * 공통 자동 바인딩 로직은 AbstractExtensionServiceProvider 가 보유하며,
 * 본 클래스는 PluginManager 를 통한 확장 해석만 담당합니다. 자식 클래스는
 * `$pluginIdentifier` 속성으로 플러그인 식별자를 지정합니다.
 *
 * 글로벌 컨테이너 바인딩으로 코어 `CacheInterface` 를 덮어쓰는 패턴은
 * 금지됩니다. 캐시·스토리지가 필요한 서비스 클래스명을 `$cacheServices`
 * / `$storageServices` 배열에 등록하면 Laravel contextual binding 으로
 * 해당 플러그인 도메인의 인스턴스가 자동 주입됩니다.
 */
abstract class BasePluginServiceProvider extends AbstractExtensionServiceProvider
{
    /**
     * 플러그인 식별자 (vendor-plugin). 자식 클래스에서 반드시 정의.
     */
    protected string $pluginIdentifier;

    /**
     * 플러그인 인스턴스를 해석합니다.
     *
     * 정상 경로: PluginManager 가 활성 플러그인 인스턴스를 반환합니다.
     *
     * Fallback: 테스트 격리 환경 / 플러그인이 PluginManager 에 등록되기 전 시점
     * (ServiceProvider 의 register 단계가 PluginManager 의 디스커버리보다 앞서 호출되는
     * 컨테이너 해석 등) 에는 PluginCacheDriver / PluginStorageDriver 를 식별자만으로
     * 직접 생성한 in-memory 어댑터를 반환합니다. AbstractPlugin::getCache() /
     * getStorage() 의 기본 구현과 동일한 키 prefix 와 디스크를 사용하므로 운영
     * 동작은 보존됩니다.
     */
    protected function resolveExtension(): CacheableExtensionInterface
    {
        $plugin = $this->app->make(PluginManager::class)
            ->getPlugin($this->extensionIdentifier);

        if ($plugin !== null) {
            return $plugin;
        }

        return new InlinePluginExtensionAdapter($this->extensionIdentifier);
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
     * `$pluginIdentifier` 값을 부모의 `$extensionIdentifier` 로 미러링합니다.
     */
    private function ensureIdentifierAlias(): void
    {
        if (isset($this->pluginIdentifier) && ! isset($this->extensionIdentifier)) {
            $this->extensionIdentifier = $this->pluginIdentifier;
        }
    }
}

/**
 * PluginManager 가 식별자에 해당하는 플러그인 인스턴스를 보유하지 않는 컨텍스트
 * (테스트 격리 / 플러그인 디스커버리 이전 컨테이너 해석 등) 에서 사용되는 어댑터.
 *
 * AbstractPlugin::getCache() / getStorage() 의 기본 구현과 동일한 키 prefix 와
 * 디스크 정책을 따르도록 PluginCacheDriver / PluginStorageDriver 를 식별자만으로
 * 직접 생성합니다.
 *
 * @internal BasePluginServiceProvider 의 fallback 전용. 외부에서 직접 참조하지 마세요.
 */
final class InlinePluginExtensionAdapter implements CacheableExtensionInterface
{
    private ?CacheInterface $cache = null;

    private ?StorageInterface $storage = null;

    /**
     * @param  string  $identifier  플러그인 식별자 (vendor-plugin)
     */
    public function __construct(private readonly string $identifier) {}

    /**
     * 플러그인 식별자를 반환합니다.
     *
     * @return string 플러그인 식별자
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * 플러그인 도메인 캐시 드라이버를 반환합니다.
     *
     * @return CacheInterface PluginCacheDriver 인스턴스
     */
    public function getCache(): CacheInterface
    {
        return $this->cache ??= new PluginCacheDriver($this->identifier);
    }

    /**
     * 플러그인 도메인 스토리지 드라이버를 반환합니다.
     *
     * @return StorageInterface PluginStorageDriver 인스턴스
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage ??= new PluginStorageDriver($this->identifier);
    }
}
