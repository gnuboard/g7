<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Container;

use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimEventRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimGroupRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoRoleGrantRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoSessionRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoUserAttributeRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\ProvisioningAccountRepository;
use Plugins\Sirsoft\Sso\Repositories\ScimEventRepository;
use Plugins\Sirsoft\Sso\Repositories\ScimGroupRepository;
use Plugins\Sirsoft\Sso\Repositories\SsoRoleGrantRepository;
use Plugins\Sirsoft\Sso\Repositories\SsoSessionRepository;
use Plugins\Sirsoft\Sso\Repositories\SsoUserAttributeRepository;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\Provisioning\DeprovisionService;
use Plugins\Sirsoft\Sso\Services\SessionService;

/**
 * 컨테이너 바인딩 — 프로비저닝(SCIM, 주기 점검, 퇴사), 역할, 속성, 세션 영역.
 *
 * `Bootstrap\Container` 가 영역별 목록을 합친다. 이 영역의 바인딩은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Provisioning
{
    /**
     * 저장소 인터페이스 ↔ 구현체 바인딩.
     *
     * @return array<class-string, class-string> 인터페이스 → 구현체
     */
    public static function repositories(): array
    {
        return [
            ProvisioningAccountRepositoryInterface::class => ProvisioningAccountRepository::class,
            SsoSessionRepositoryInterface::class => SsoSessionRepository::class,
            SsoUserAttributeRepositoryInterface::class => SsoUserAttributeRepository::class,
            SsoRoleGrantRepositoryInterface::class => SsoRoleGrantRepository::class,
            ScimGroupRepositoryInterface::class => ScimGroupRepository::class,
            ScimEventRepositoryInterface::class => ScimEventRepository::class,
        ];
    }

    /**
     * 플러그인 캐시(CacheInterface)를 주입받을 서비스.
     *
     * 세션 서비스는 1회용 로그아웃 티켓, 퇴사와 속성 서비스는 「한 번만 알림」 표시를 플러그인 캐시에 둔다.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function cacheServices(): array
    {
        return [
            SessionService::class,
            DeprovisionService::class,
            AttributeSyncService::class,
        ];
    }

    /**
     * 요청 단위 싱글턴(`scoped`)으로 둘 서비스.
     *
     * 세션 서비스는 로그아웃 필터가 본 토큰 ID 를 같은 요청의 로그아웃 완료 훅까지 기억해야 한다.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function scoped(): array
    {
        return [
            SessionService::class,
        ];
    }
}
