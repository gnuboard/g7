<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NullMiddleware
{
    /**
     * CSRF 검증을 비활성화하기 위한 no-op 미들웨어입니다.
     *
     * SPA 프론트엔드가 CSRF 토큰을 전송하지 않으므로,
     * EnsureFrontendRequestsAreStateful의 CSRF 미들웨어를 대체합니다.
     * API 인증은 Bearer 토큰으로 수행되므로 CSRF 보호가 불필요합니다.
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
