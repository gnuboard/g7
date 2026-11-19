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
 * 개발자 화면(routes/custom.php) 회원 필수 미들웨어 — 별칭 `custom.auth` (#792 W8).
 *
 * 로그인 판정은 {@see ScreenAuth}(서버 세션 + 그 로그인의 토큰 생존). 비회원이면 G7 로그인 화면으로 보내고
 * 로그인 뒤 돌아올 주소를 `redirect` 로 넘긴다. JSON 을 기대하는 요청은 401.
 *
 * @since 7.2.0
 */
class RequireScreenUser
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
        if (ScreenAuth::user() !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return ResponseHelper::unauthorized('auth.unauthenticated');
        }

        $login = ScreenUrl::path('auth.login', '/login');

        // 보안: 상대 경로를 redirect() 에 넘기면 요청 Host 로 절대화된다 — 사이트 주소로 만든다(필터가 준 절대 URL 은 그대로)
        return redirect(SiteUrl::absolute($login.(str_contains($login, '?') ? '&' : '?').'redirect='.rawurlencode($request->getRequestUri())));
    }
}
