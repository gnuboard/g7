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
 * 문서 루트 배치(프로젝트 폴더 = 문서 루트)에서 루트 실존 디렉토리로 위임된 요청(`G7_ROOTDIR` 표식)도
 * 건너뛴다 — 그 디렉토리는 `public/` 아래에 없지만 mod_dir 가 슬래시를 붙여 되던지므로, 여기서 떼면
 * 무한 301 이 된다(`public/.htaccess` 의 슬래시 보정이 같은 표식으로 건너뛰는 것과 짝이다).
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

        if ($normalized === $path || is_dir(public_path(ltrim($path, '/'))) || $this->isRootDirectoryDelegation($request)) {
            return $next($request);
        }

        $queryString = $request->server->get('QUERY_STRING');
        $target = $normalized.(is_string($queryString) && $queryString !== '' ? '?'.$queryString : '');

        return redirect()->away(SiteUrl::forPath($target), 301);
    }

    /**
     * 루트 `.htaccess` 가 프로젝트 루트의 실존 디렉토리 요청을 위임하며 실은 표식이 있는지 봅니다.
     *
     * 내부 리다이렉트를 지날 때마다 `REDIRECT_` 가 덧붙으므로 진입 스크립트가 `G7_ROUTE` 를 찾을 때처럼
     * 0~4겹까지 올려 가며 찾는다.
     *
     * @param  Request  $request  현재 요청
     * @return bool 루트 실존 디렉토리 위임이면 true
     */
    private function isRootDirectoryDelegation(Request $request): bool
    {
        for ($depth = 0, $prefix = ''; $depth <= 4; $depth++, $prefix .= 'REDIRECT_') {
            if ($request->server->get($prefix.'G7_ROOTDIR') === '1') {
                return true;
            }
        }

        return false;
    }
}
