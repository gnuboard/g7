<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Listeners;

use Plugins\Sirsoft\Sso\Services\Ldap\RegisterCredentialVerifierListener;

/**
 * 훅 리스너 — LDAP/Active Directory 드라이버와 코어 외부 자격 증명 검증자 영역.
 *
 * `Bootstrap\Listeners::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Ldap
{
    /**
     * 이 영역의 훅 리스너 목록을 반환합니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        return [
            RegisterCredentialVerifierListener::class,
        ];
    }
}
