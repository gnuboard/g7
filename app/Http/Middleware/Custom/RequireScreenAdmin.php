<?php

namespace App\Http\Middleware\Custom;

use App\Helpers\ResponseHelper;
use App\Services\Screen\ScreenAuth;
use App\Support\ScreenUrl;
use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 개발자 관리자 화면(routes/custom.php 의 `/admin/...`) 관리자 필수 미들웨어 — 별칭 `custom.admin`.
 *
 * 비회원이면 관리자 로그인 화면으로 보내고(`redirect` 로 복귀 주소), 회원이지만 관리자가 아니면 403.
 * 관리자 판정은 관리자 API 의 `admin` 미들웨어와 같은 함수(`User::isAdmin()`)다.
 *
 * @since 7.2.0
 */
class RequireScreenAdmin
{
    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = ScreenAuth::user();

        if ($user === null) {
            if ($request->expectsJson()) {
                return ResponseHelper::unauthorized('auth.unauthenticated');
            }

            $login = ScreenUrl::path('admin.login', '/admin/login', [], ScreenUrl::SCOPE_ADMIN);

            // 보안: 상대 경로를 redirect() 에 넘기면 요청 Host 로 절대화된다 — 사이트 주소로 만든다(필터가 준 절대 URL 은 그대로)
            return redirect(SiteUrl::absolute($login.(str_contains($login, '?') ? '&' : '?').'redirect='.rawurlencode($request->getRequestUri())));
        }

        if (! $user->isAdmin()) {
            abort(403, __('auth.admin_required'));
        }

        return $next($request);
    }
}
