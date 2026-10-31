<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Container;

use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;
use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcMetadata;
use Plugins\Sirsoft\Sso\Identity\SsoReauthIdentityProvider;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcKeys;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\IdentityProviderRepository;
use Plugins\Sirsoft\Sso\Repositories\LoginAttemptRepository;
use Plugins\Sirsoft\Sso\Repositories\SsoAccountRepository;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\PendingStore;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Services\StateStore;

/**
 * 컨테이너 바인딩 — 플러그인 기반, OIDC, 흐름 영역.
 *
 * `Bootstrap\Container` 가 영역별 목록을 합친다. 이 영역의 바인딩은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Core
{
    /**
     * 저장소 인터페이스 ↔ 구현체 바인딩.
     *
     * @return array<class-string, class-string> 인터페이스 → 구현체
     */
    public static function repositories(): array
    {
        return [
            IdentityProviderRepositoryInterface::class => IdentityProviderRepository::class,
            SsoAccountRepositoryInterface::class => SsoAccountRepository::class,
            LoginAttemptRepositoryInterface::class => LoginAttemptRepository::class,
        ];
    }

    /**
     * 플러그인 캐시(CacheInterface)를 주입받을 서비스.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function cacheServices(): array
    {
        return [
            StateStore::class,
            PendingStore::class,
            LoginFlowService::class,
            OidcMetadata::class,
            OidcDriver::class,
            MockGate::class,
            MockOidcKeys::class,
            MockOidcController::class,
            SsoReauthIdentityProvider::class,
        ];
    }

    /**
     * 요청 단위 싱글턴(`scoped`)으로 둘 서비스.
     *
     * 레지스트리는 드라이버 목록과 확장 필터를 한 요청에서 한 번만 만든다. state 저장소는 한 요청 안에서 새로 구운 브라우저 묶음값을
     * 기억해야 같은 요청의 두 번째 호출이 다른 값을 굽지 않는다.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function scoped(): array
    {
        return [
            ProtocolRegistry::class,
            StateStore::class,
        ];
    }
}
