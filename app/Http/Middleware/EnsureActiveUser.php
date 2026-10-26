<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 공통 회원 상태 관문 — api 그룹 전체에 붙어 인증 미들웨어 뒤에서 돈다.
 *
 * 회원 상태(차단·탈퇴·휴면·가입 후 인증 대기 등) 재검사를 라우트마다 붙이지 않아도 되게 한다. 코어·모듈·플러그인 API 가 모두
 * 같은 판정을 받는다.
 *
 * - 인증 미들웨어(`auth:sanctum`, `optional.sanctum`)가 없는 라우트 → 판정하지 않음
 * - 인증된 회원 없음, Active → 통과
 * - 라우트에 `check.user_status` 가 이미 있음 → 그쪽에 맡김(이중 판정 방지, 읽기 허용 파라미터 보존)
 * - 라우트에 표식 `allow.inactive_user` 가 있음 → 통과(가입 후 인증 대기 회원의 본인인증 경로)
 * - 그 밖: `auth:sanctum` 라우트면 403(`CheckUserStatus` 와 같은 문구), `optional.sanctum` 라우트면 손님으로 낮춰 통과
 *   (휴면·대기 회원도 공개 목록과 상세는 본다)
 *
 * @since 7.2.0
 */
class EnsureActiveUser
{
    /** 비활성 회원 통과 표식 별칭 */
    public const ALLOW_INACTIVE_ALIAS = 'allow.inactive_user';

    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $middleware = $this->routeMiddleware($request);
        $required = $this->hasMiddleware($middleware, ['auth:sanctum', 'auth']);
        $optional = $this->hasMiddleware($middleware, ['optional.sanctum', OptionalSanctumMiddleware::class]);

        if (! $required && ! $optional) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->status === UserStatus::Active->value) {
            return $next($request);
        }

        if ($this->hasMiddleware($middleware, ['check.user_status', CheckUserStatus::class])
            || $this->hasMiddleware($middleware, [self::ALLOW_INACTIVE_ALIAS, AllowInactiveUser::class])) {
            return $next($request);
        }

        if ($required) {
            return ResponseHelper::forbidden($this->messageKey((string) $user->status));
        }

        // 공개 라우트 — 손님으로 낮춘다. 세 가지를 모두 지워야 Sanctum 이 Bearer 로 회원을 다시 해석하지 못한다.
        $request->headers->remove('Authorization');
        Auth::forgetGuards();
        $request->setUserResolver(fn () => null);

        return $next($request);
    }

    /**
     * 회원 상태의 거절 문구 키를 돌려줍니다 (`CheckUserStatus` 와 같은 표).
     *
     * @param  string  $status  회원 상태
     * @return string 문구 키
     */
    private function messageKey(string $status): string
    {
        return match ($status) {
            UserStatus::Inactive->value => 'auth.account_inactive',
            UserStatus::Blocked->value => 'auth.account_blocked',
            UserStatus::Withdrawn->value => 'auth.account_withdrawn',
            UserStatus::PendingVerification->value => 'auth.account_pending_verification',
            UserStatus::Dormant->value => 'auth.account_dormant',
            default => 'auth.permission_denied',
        };
    }

    /**
     * 현재 라우트의 미들웨어 목록을 돌려줍니다 (그룹·컨트롤러 미들웨어 포함).
     *
     * @param  Request  $request  HTTP 요청
     * @return array<int, string> 미들웨어 이름
     */
    private function routeMiddleware(Request $request): array
    {
        $route = $request->route();

        if (! $route instanceof \Illuminate\Routing\Route) {
            return [];
        }

        return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
    }

    /**
     * 목록에 이름(파라미터 무시)이 있는지 판정합니다.
     *
     * `auth` 는 파라미터가 없거나 `sanctum` 일 때만 일치로 본다(다른 가드는 이 관문 대상이 아니다).
     *
     * @param  array<int, string>  $middleware  미들웨어 목록
     * @param  array<int, string>  $names  찾을 이름
     * @return bool 있으면 true
     */
    private function hasMiddleware(array $middleware, array $names): bool
    {
        foreach ($middleware as $entry) {
            if (in_array($entry, $names, true)) {
                return true;
            }

            [$name] = explode(':', $entry, 2);

            if ($name !== 'auth' && in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }
}
