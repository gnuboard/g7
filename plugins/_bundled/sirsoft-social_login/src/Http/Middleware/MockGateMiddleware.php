<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * 모의 제공자 라우트군의 게이트 — 게이트(`sirsoft-social_login:mock --enable`)가 꺼져 있으면 404 로 끝낸다.
 *
 * 부착은 플러그인 `getMiddleware()` 선언 한 곳뿐이고 대상은 모의 라우트명 서브트리다. 핸들러마다 게이트를 적으면 모의
 * 라우트를 더할 때 빠뜨리게 된다(docs/backend/routing.md 「디버그·개발 라우트는 그룹 단위로 게이트한다」).
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
