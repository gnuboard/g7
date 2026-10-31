<?php

namespace App\Http\Middleware;

use App\Support\RequestPath;
use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 끝·연속 슬래시가 붙은 화면 요청을 정규형 경로로 301 이동시킵니다 (공개#156).
 *
 * `/admin/` 이나 `/board//free` 는 Laravel 라우터에서는 catch-all 에 걸리지만, 템플릿 라우트 판정·
 * SEO 렌더·SEO 캐시 키·방문 통계는 요청 경로 문자열을 그대로 쓰므로 같은 화면이 두 갈래가 된다.
 * Apache 는 `public/.htaccess` 의 끝 슬래시 301 규칙이 이를 가렸지만 nginx 에서는 HTTP 404 가 났다.
 * 그래서 웹 서버와 무관하게 이 미들웨어가 정규형(`RequestPath::normalize()`)으로 모은다.
 *
 * 대상은 화면 셸 라우트(GET·HEAD)뿐이다. 존재하지 않는 주소도 먼저 이동시키고 이동한 곳에서
 * 404 를 판정한다. 실제 디렉터리 경로는 Apache `RewriteCond !-d` 처럼 건너뛴다(이동 루프 방지).
 * 쿼리스트링은 원문 그대로 붙인다 — `getQueryString()` 은 파라미터 순서를 바꾼다.
 * 이동 주소는 `canonical.host` 와 같이 `SiteUrl::forPath()` 로 사이트 주소 안에서만 만든다 —
 * `//evil.com/`·`/\evil.com/` 같은 경로도 `https://<사이트>/evil.com` 으로 간다.
 */
class RedirectToCanonicalPath
{
    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  현재 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 정규형 경로로의 301 또는 다음 미들웨어 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        $normalized = RequestPath::normalize($path);

        if ($normalized === $path || is_dir(public_path(ltrim($path, '/')))) {
            return $next($request);
        }

        $queryString = $request->server->get('QUERY_STRING');
        $target = $normalized.(is_string($queryString) && $queryString !== '' ? '?'.$queryString : '');

        return redirect()->away(SiteUrl::forPath($target), 301);
    }
}
