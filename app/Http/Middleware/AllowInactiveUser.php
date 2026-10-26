<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 공통 회원 상태 관문({@see EnsureActiveUser}) 통과 표식.
 *
 * 하는 일은 없다 — 라우트에 이 별칭(`allow.inactive_user`)이 붙어 있으면 공통 관문이 비활성 회원을 막지 않는다.
 * 가입 후 인증 대기 회원이 본인인증을 마쳐 활성화하는 경로(`api.identity.*`)에만 붙인다.
 *
 * @since 7.2.0
 */
class AllowInactiveUser
{
    /**
     * 요청을 그대로 넘깁니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
