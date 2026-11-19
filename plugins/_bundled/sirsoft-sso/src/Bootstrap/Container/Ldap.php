<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Container;

use Plugins\Sirsoft\Sso\Services\Ldap\DirectoryClientInterface;
use Plugins\Sirsoft\Sso\Services\Ldap\ExtLdapDirectoryClient;

/**
 * 컨테이너 바인딩 — LDAP/Active Directory 영역.
 *
 * `Bootstrap\Container` 가 영역별 목록을 합친다. 이 영역의 바인딩은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Ldap
{
    /**
     * 저장소 인터페이스 ↔ 구현체 바인딩.
     *
     * @return array<class-string, class-string> 인터페이스 → 구현체
     */
    public static function repositories(): array
    {
        // 디렉터리 연결은 상태가 있는 객체라 싱글턴이 아니라 호출마다 새로 만든다(`bind`) — 시험은 가짜 디렉터리로 바꿔 끼운다.
        return [
            DirectoryClientInterface::class => ExtLdapDirectoryClient::class,
        ];
    }

    /**
     * 플러그인 캐시(CacheInterface)를 주입받을 서비스.
     *
     * @return array<int, class-string> 서비스 클래스
     */
    public static function cacheServices(): array
    {
        return [];
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
