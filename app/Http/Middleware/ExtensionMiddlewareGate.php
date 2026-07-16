<?php

namespace App\Http\Middleware;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * 확장 선언 미들웨어의 self-gate 실행기.
 *
 * 확장이 getMiddleware() 로 선언한 미들웨어를 web/api 그룹에 직접 넣는 대신,
 * 이 래퍼가 그룹에 등록되어 요청 시점에 현재 라우트 이름·URI 가 각 확장 미들웨어의
 * targets 패턴에 매칭될 때만 해당 미들웨어를 동적으로 실행한다. 매칭 없으면
 * 즉시 통과 (코어 EnforceIdentityPolicy 의 라우트명 인덱스 조회 모델과 동일).
 *
 * 부팅 시점 라우트 순회로 확장 간 미들웨어를 부착할 수 없는 제약(확장 라우트는
 * booted 지연 등록 + 순서 미보장)을 요청 시점 매칭으로 원천 회피한다.
 *
 * bootstrap/app.php 에서 web/api × before_core/after_core 로 총 4회 등록된다.
 * Kernel 상 prepend/append 위치는 등록 시점 매핑이고, 게이트 실행 로직은 timing 을 안다.
 *
 * @since 7.0.5
 */
class ExtensionMiddlewareGate
{
    /**
     * @param  Application  $app  Pipeline 실행용 컨테이너
     * @param  ExtensionMiddlewareRegistryInterface  $registry  확장 미들웨어 매칭 인덱스
     */
    public function __construct(
        protected Application $app,
        protected ExtensionMiddlewareRegistryInterface $registry,
    ) {}

    /**
     * 미들웨어 진입점.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 파이프라인
     * @param  string  $group  이 래퍼가 등록된 그룹 ('web'|'api')
     * @param  string  $timing  이 래퍼 인스턴스가 담당하는 실행 시점 ('before_core'|'after_core')
     */
    public function handle(Request $request, Closure $next, string $group, string $timing): Response
    {
        // 라우트명(무명이면 빈 문자열)과 URI path(항상 존재, 선행 슬래시 없음) 둘 다 전달 —
        // 무명 SSR 셸 catch-all 은 URI 패턴 target 으로만 매칭되므로 routeName 빈 값에 조기 통과하지 않는다.
        $routeName = $request->route()?->getName() ?? '';
        $path = $request->path();
        $matched = $this->registry->resolveForRoute($routeName, $path, $group, $timing);

        if ($matched === []) {
            return $next($request);
        }

        // 매칭된 확장 미들웨어들을 Laravel Pipeline 으로 순차 실행 후 $next.
        return (new Pipeline($this->app))
            ->send($request)
            ->through($matched)
            ->then($next);
    }
}
