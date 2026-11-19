<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTPS 강제 이동 (관리자 환경설정 > 보안 > 「HTTPS 강제 적용」, #888).
 *
 * 설정 `security.force_https` 가 켜져 있고 요청이 https 가 아니면, 같은 호스트·경로·쿼리의 https 주소로 보낸다.
 * GET·HEAD 는 301, 그 밖의 메서드는 본문과 메서드를 그대로 다시 보내도록 308 이다(301 은 브라우저가 POST 를 GET 으로 바꾼다).
 *
 * https 판정은 `$request->secure()` 다 — 리버스 프록시·CDN 뒤에서는 `TRUSTED_PROXIES` 에 프록시를 적어야
 * `X-Forwarded-Proto: https` 가 인정된다. 적지 않으면 프록시가 http 로 넘기는 요청마다 이동해 무한 반복이 된다.
 * 그래서 화면은 「인증서가 준비된 뒤 켜라」고 안내하고, 잠겼을 때 끄는 명령(`php artisan settings:set security.force_https false`)을 함께 보인다.
 *
 * web·api 그룹 맨 앞에 둔다 — 점검 모드(전역)와 신뢰 프록시(전역) 판정은 그보다 먼저 끝난다. 이동 대상 호스트는 요청 Host 지만,
 * 신뢰 Host 판정(전역 `trustHosts`)을 통과한 값만 여기까지 온다.
 *
 * 적용하지 않는 경우:
 *  - 설정이 꺼져 있다(기본)
 *  - 콘솔 실행 안의 내부 요청(큐·스케줄이 만드는 SEO 미리 그리기 등) — 브라우저가 없어 이동할 곳이 없다
 *  - 설치기(`public/install`) — Laravel 밖에서 돌므로 이 미들웨어를 거치지 않는다
 */
class ForceHttps
{
    /**
     * https 가 아닌 요청을 같은 주소의 https 로 보냅니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 이동 응답 또는 다음 단계 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldRedirect($request)) {
            return $next($request);
        }

        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return redirect()->away($this->httpsUrl($request), $status);
    }

    /**
     * 이 요청을 https 로 보내야 하는지 판정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 보내야 하면 true
     */
    private function shouldRedirect(Request $request): bool
    {
        if ($request->secure()) {
            return false;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        return filter_var(g7_core_settings('security.force_https', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 같은 호스트·경로·쿼리의 https 주소를 만듭니다.
     *
     * 포트는 붙이지 않는다 — http 쪽 포트(예: 8080)가 https 에서도 열려 있다는 보장이 없어, 표준 포트(443)로 보낸다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string https 주소
     */
    private function httpsUrl(Request $request): string
    {
        return 'https://'.$request->getHost().$request->getRequestUri();
    }
}
