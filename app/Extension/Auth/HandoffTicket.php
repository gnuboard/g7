<?php

namespace App\Extension\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * 브라우저에 묶은 로그인 인계 코드와 그 묶음 쿠키.
 *
 * {@see \App\Services\Auth\LoginHandoffService::issueTicket()} 의 반환값. API 응답에서 인계 코드를 발급하는 호출자는
 * 응답에 `->withCookie($ticket->cookie)` 로 쿠키를 붙인다 — api 미들웨어 그룹에는 쿠키 대기열(`Cookie::queue`)을 응답에
 * 붙이는 미들웨어가 없다. 웹 라우트에서 발급하면 `issue()` 가 쿠키를 대기열에 넣으므로 이 DTO 가 필요 없다.
 *
 * @since 7.2.0
 */
final class HandoffTicket
{
    /**
     * @param  string  $code  인계 코드 (URL 에 실어도 되는 문자만)
     * @param  Cookie  $cookie  묶음 쿠키 `g7_login_bind` (HttpOnly, Secure, SameSite=Lax, Path=/api/auth, 120초)
     */
    public function __construct(
        public readonly string $code,
        public readonly Cookie $cookie,
    ) {}
}
