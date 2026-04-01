<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * 관리자 권한이 있는 사용자만 접근을 허용합니다.
     *
     * @param Request $request HTTP 요청
     * @param Closure $next 다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 인증되지 않은 사용자
        if (!Auth::check()) {
            return ResponseHelper::unauthorized('auth.unauthenticated');
        }

        $user = Auth::user();

        // 관리자 권한 확인
        if (!$user->isAdmin()) {
            return ResponseHelper::forbidden('auth.admin_required');
        }

        return $next($request);
    }
}
