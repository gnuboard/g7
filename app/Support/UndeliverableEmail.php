<?php

namespace App\Support;

/**
 * 메일을 보낼 수 없는 이메일 주소 판정
 *
 * 외부 인증(소셜 로그인 등)이 이메일을 주지 않을 때 확장이 `…@xxx.invalid` 같은 대체 주소를 발급해 회원을 만든다.
 * `.invalid` 는 RFC 2606 이 「존재하지 않음」을 보장하는 예약 최상위 도메인이라 그 주소로 보낸 메일은 절대 닿지 않는다.
 * 이런 회원은 「이메일 없음」과 같게 다룬다 — 메일 2단계 인증을 요구하지 않고(요구하면 영영 로그인할 수 없다),
 * 메일 알림·비밀번호 재설정 메일을 보내지 않는다.
 *
 * @since 7.2.0
 */
final class UndeliverableEmail
{
    /**
     * 메일을 보낼 수 없는 주소인지 판정합니다.
     *
     * @param  string|null  $email  이메일 주소
     * @return bool 비어 있거나 예약 최상위 도메인 `.invalid` 면 true
     */
    public static function is(?string $email): bool
    {
        $email = trim((string) $email);

        if ($email === '') {
            return true;
        }

        $at = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(rtrim(substr($email, $at + 1), '.'));

        return $domain === 'invalid' || str_ends_with($domain, '.invalid');
    }
}
