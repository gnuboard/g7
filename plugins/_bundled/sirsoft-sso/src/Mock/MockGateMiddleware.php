<?php

namespace Plugins\Sirsoft\Sso\Mock;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 모의 IdP 라우트군의 게이트 — 게이트(`sirsoft-sso:mock --enable`)가 꺼져 있으면 404 로 끝낸다.
 *
 * 부착은 플러그인 `getMiddleware()` 선언 한 곳뿐이고 대상은 모의 라우트명 서브트리(`web.plugins.sirsoft-sso.mock.*`)다. 핸들러마다
 * 게이트를 적으면 모의 라우트를 더할 때 빠뜨리게 된다.
 *
 * @since 1.0.0
 */
class MockGateMiddleware
{
    /**
     * @param  MockGate  $gate  활성 게이트
     */
    public function __construct(private readonly MockGate $gate) {}

    /**
     * 게이트가 켜져 있을 때만 요청을 넘깁니다.
     *
     * @param  Request  $request  요청
     * @param  Closure  $next  다음 처리
     * @return Response 응답 (꺼져 있으면 404)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->gate->enabled()) {
            abort(404);
        }

        return $next($request);
    }
}
