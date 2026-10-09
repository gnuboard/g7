<?php

namespace App\Http\Middleware;

use App\Enums\PermissionType;
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
     * 읽기 허용 권한(`check.user_status:core.profile.read`)을 지정한 라우트에서는, Active 가 아닌
     * 회원이라도 **읽기 요청(GET·HEAD)** 이고 그 회원의 역할이 지정 권한(사용자 타입)을 가지면
     * 통과시킵니다. 마이페이지가 계정 상태 배지·안내를 그리려면 본인 정보 조회가 필요한데, 상태로
     * 통째 막으면 그 안내가 보일 길이 없었다(결정 2026-10-07 「역할 권한으로 판단」).
     * 이 허용은 파라미터를 단 라우트(본인 정보 조회 두 곳)에만 걸리고, 쓰기 메서드는 파라미터가
     * 있어도 상태로 막는다. 로그인 자체의 상태 거부(AuthService)와는 무관하다 — 이미 받은 토큰의 조회만 해당.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @param  string  ...$readPermissions  비활성 회원의 읽기 요청을 허용할 사용자 권한 식별자 (하나라도 있으면 허용)
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next, string ...$readPermissions): Response
    {
        $user = $request->user();

        // 미인증 사용자는 통과 (optional.sanctum 환경에서 guest 허용)
        if (! $user) {
            return $next($request);
        }

        // Active 상태만 통과 — 단, 읽기 허용 권한을 지정한 라우트의 읽기 요청은 역할 권한으로 판단한다
        if ($user->status !== UserStatus::Active->value
            && ! $this->allowsReadByPermission($request, $user, $readPermissions)) {
            $messageKey = match ($user->status) {
                UserStatus::Inactive->value => 'auth.account_inactive',
                UserStatus::Blocked->value => 'auth.account_blocked',
                UserStatus::Withdrawn->value => 'auth.account_withdrawn',
                UserStatus::PendingVerification->value => 'auth.account_pending_verification',
                // 로그인 화면과 정책을 맞춘다 — 휴면 회원이 전환 전에 받아 둔 토큰으로 계속
                // 쓸 수 있으면 "장기 미접속이라 휴면으로 돌렸다" 는 판정이 무의미해진다.
                // 해제 경로는 로그인 한 곳뿐이므로(AuthService::restoreDormantAccount) 여기서
                // 되돌리지 않고 안내만 한다.
                UserStatus::Dormant->value => 'auth.account_dormant',
                default => 'auth.permission_denied',
            };

            return ResponseHelper::forbidden($messageKey);
        }

        return $next($request);
    }

    /**
     * 비활성 회원의 읽기 요청을 역할 권한으로 허용할지 판정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  mixed  $user  인증된 사용자
     * @param  array<int, string>  $readPermissions  허용 권한 식별자 목록
     * @return bool 허용하면 true
     */
    private function allowsReadByPermission(Request $request, mixed $user, array $readPermissions): bool
    {
        if ($readPermissions === [] || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if (! method_exists($user, 'hasPermission')) {
            return false;
        }

        foreach ($readPermissions as $permission) {
            if ($user->hasPermission($permission, PermissionType::User)) {
                return true;
            }
        }

        return false;
    }
}
