<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * 특정 역할을 가진 사용자만 접근을 허용합니다.
     *
     * @param Request $request HTTP 요청
     * @param Closure $next 다음 미들웨어
     * @param string $role 필요한 역할명
     * @param bool $requireAll 여러 역할시 모두 필요한지 여부
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next, string $role, bool $requireAll = true): Response
    {
        // 인증되지 않은 사용자
        if (!Auth::check()) {
            return ResponseHelper::unauthorized('messages.auth.unauthenticated');
        }

        $user = Auth::user();
        $roles = explode('|', $role);

        // 역할 확인
        if (count($roles) === 1) {
            if (!$user->hasRole($roles[0])) {
                return ResponseHelper::forbidden('messages.auth.role_denied', [
                    'required_role' => $roles[0]
                ]);
            }
        } else {
            if (!$user->hasRoles($roles, $requireAll)) {
                return ResponseHelper::forbidden('messages.auth.role_denied', [
                    'required_roles' => $roles,
                    'require_all' => $requireAll
                ]);
            }
        }

        return $next($request);
    }
}
