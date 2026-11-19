<?php

namespace Plugins\Sirsoft\Sso\Services\Ldap;

/**
 * 서버의 PHP LDAP 확장(`ext-ldap`) 유무.
 *
 * 공유 호스팅에는 ext-ldap 이 없는 곳이 많다 — 없으면 LDAP 드라이버만 「사용 불가」이고 나머지 프로토콜은 정상이다. 시험이 「확장 없음」을
 * 흉내 낼 수 있게 컨테이너로 주입한다.
 *
 * @since 1.0.0
 */
class LdapExtension
{
    /**
     * ext-ldap 이 로드돼 있는지 확인합니다.
     *
     * @return bool 있으면 true
     */
    public function loaded(): bool
    {
        return extension_loaded('ldap') && function_exists('ldap_connect');
    }
}
