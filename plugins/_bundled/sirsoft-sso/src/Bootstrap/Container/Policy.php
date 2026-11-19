<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Container;

use Plugins\Sirsoft\Sso\Listeners\Admin\IdpFailureMonitorListener;
use Plugins\Sirsoft\Sso\Repositories\Policy\AdminAccountRepository;
use Plugins\Sirsoft\Sso\Repositories\Policy\AdminIdpRepository;
use Plugins\Sirsoft\Sso\Repositories\Policy\AdminLogRepository;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminIdpRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminLogRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\SitePolicyRepository;

/**
 * 컨테이너 바인딩 — 정책과 관리자 화면 영역.
 *
 * `Bootstrap\Container` 가 영역별 목록을 합친다. 이 영역의 바인딩은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Policy
{
    /**
     * 저장소 인터페이스 ↔ 구현체 바인딩.
     *
     * @return array<class-string, class-string> 인터페이스 → 구현체
     */
    public static function repositories(): array
    {
        return [
            SitePolicyRepositoryInterface::class => SitePolicyRepository::class,
            AdminIdpRepositoryInterface::class => AdminIdpRepository::class,
            AdminAccountRepositoryInterface::class => AdminAccountRepository::class,
            AdminLogRepositoryInterface::class => AdminLogRepository::class,
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
            IdpFailureMonitorListener::class,
        ];
    }

    /**
     * 요청 단위 싱글턴(`scoped`)으로 둘 서비스.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function scoped(): array
    {
        return [];
    }
}
