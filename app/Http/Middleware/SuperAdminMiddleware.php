<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 최고관리자 전용 라우트 게이트.
 *
 * 최고관리자 판정은 회원의 최고관리자 표지(`users.is_super`, `User::isSuperAdmin()`)다 — 역할이 아니다.
 * 코어는 최고관리자 역할을 만들지 않으므로 역할 식별자로 판정하면 아무도 통과하지 못한다.
 *
 * @since 7.2.0
 */
class SuperAdminMiddleware
{
    /**
     * 최고관리자가 아니면 요청을 거부합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 응답 (비인증 401 · 최고관리자 아님 403)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return ResponseHelper::unauthorized('auth.unauthenticated');
        }

        if (! Auth::user()->isSuperAdmin()) {
            return ResponseHelper::forbidden('auth.super_admin_required');
        }

        return $next($request);
    }
}
