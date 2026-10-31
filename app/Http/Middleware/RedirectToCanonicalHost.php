<?php

namespace App\Http\Middleware;

use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 사이트 주소가 아닌 Host 로 들어온 화면 요청을 같은 경로의 사이트 주소로 이동시킵니다.
 *
 * 허용 도메인(TrustHosts)은 사이트 주소의 하위 도메인·www 쌍(`www.` 를 붙이거나 뗀 주소)·포트만
 * 바꾼 Host·별칭 도메인을 통과시킨다.
 * 그런 주소로 화면을 연 사용자는 본인인증·결제를 그 주소에서 시작하게 되는데, 결과 전달 주소는
 * 사이트 주소로 고정돼 있어 흐름이 어긋나고, 화면 셸의 자산 주소도 요청 Host 를 따른다. 그래서
 * 화면은 사이트 주소 한 곳에서만 그린다. 사이트 주소가 `www.example.com` 이면 `example.com` 으로,
 * `example.com` 이면 `www.example.com` 으로 들어온 화면 요청도 이 규칙대로 사이트 주소로 옮긴다.
 *
 * 대상은 화면 셸 라우트(GET·HEAD)뿐이다 — API·확장 라우트(PG·본인인증 콜백·브리지)·사이트맵·
 * 정적 파일은 붙이지 않는다. scheme 은 비교하지 않는다(TLS 를 프록시가 끝내는데 신뢰 프록시를
 * 두지 않은 배포에서 무한 이동이 생긴다 — http→https 는 별도 설정의 몫). 설치 전·사이트 주소
 * 미상·`CANONICAL_HOST_REDIRECT=false` 이면 통과시킨다.
 */
class RedirectToCanonicalHost
{
    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  현재 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 사이트 주소로의 301 또는 다음 미들웨어 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldRedirect($request)) {
            return $next($request);
        }

        return redirect()->away(SiteUrl::forPath($request->getRequestUri()), 301);
    }

    /**
     * 사이트 주소로 이동시켜야 하는 요청인지 판정합니다.
     *
     * @param  Request  $request  현재 요청
     * @return bool 이동 대상이면 true
     */
    private function shouldRedirect(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if (! config('app.installer_completed') || ! config('trustedhosts.canonical_redirect', true)) {
            return false;
        }

        $canonical = SiteUrl::hostWithPort();
        if ($canonical === '') {
            return false;
        }

        return strtolower($request->getHttpHost()) !== $canonical;
    }
}
