<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * 인증된 사용자의 상태가 Active인지 확인합니다.
     *
     * auth:sanctum 또는 optional.sanctum 이후에 체인되어야 합니다.
     * Active 상태가 아닌 사용자는 403 Forbidden으로 차단합니다.
     * 미인증 사용자(guest)는 통과시킵니다.
     *
     * @param Request $request HTTP 요청
     * @param Closure $next 다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 미인증 사용자는 통과 (optional.sanctum 환경에서 guest 허용)
        if (! $user) {
            return $next($request);
        }

        // Active 상태만 통과
        if ($user->status !== UserStatus::Active->value) {
            $messageKey = match ($user->status) {
                UserStatus::Inactive->value => 'auth.account_inactive',
                UserStatus::Blocked->value => 'auth.account_blocked',
                UserStatus::Withdrawn->value => 'auth.account_withdrawn',
                default => 'auth.permission_denied',
            };

            return ResponseHelper::forbidden($messageKey);
        }

        return $next($request);
    }
}
