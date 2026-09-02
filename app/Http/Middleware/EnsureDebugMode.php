<?php

namespace App\Http\Middleware;

use App\Support\DevTools\DebugGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 디버그 모드가 꺼져 있으면 요청을 403 으로 차단합니다 (DevTools 엔드포인트 게이트).
 *
 * 왜 미들웨어인가:
 *   이 게이트는 원래 `routes/devtools.php` 의 **핸들러 안**에 `if (! DebugGate::isEnabled())`
 *   블록으로 들어 있었다. 그러다 보니 8개 라우트 중 POST 3종에만 붙고 GET 4종·DELETE 1종은
 *   빠졌다 — 라우트를 추가할 때 게이트를 함께 적는 것을 잊으면 그 라우트만 조용히 열린다.
 *   실제로 `DELETE /_boost/g7-debug/clear` 는 production·`APP_DEBUG=false` 에서도 미인증
 *   200 으로 `storage/debug-dump` 전체를 지웠다(공개#128).
 *
 *   게이트가 하나라도 빠지면 예외도 로그도 남지 않는다 — 그 엔드포인트가 정상 응답하는 것이
 *   유일한 증상이다. 그래서 판정을 그룹 미들웨어 **단일 지점**으로 올려 라우트 추가가 게이트
 *   부착과 분리될 수 없게 만든다. 부착 지점은 `bootstrap/app.php` 의 devtools 래퍼 하나다.
 *
 * 판정 SSoT 는 `DebugGate::isEnabled()` 를 그대로 쓴다 (`config('app.debug')` 또는 관리자
 * 환경설정 `debug.mode`). 응답 형태도 종전 핸들러 블록과 동일하게 유지한다 — 이미 배포된
 * 브라우저 인젝션 스크립트와 MCP 도구가 이 shape 을 읽는다.
 */
class EnsureDebugMode
{
    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답 (디버그 모드 OFF 시 403 JSON)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DebugGate::isEnabled()) {
            return response()->json([
                'status' => 'error',
                'message' => __('devtools.debug_disabled'),
            ], 403);
        }

        return $next($request);
    }
}
