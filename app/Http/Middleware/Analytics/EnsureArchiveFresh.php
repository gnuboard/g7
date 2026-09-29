<?php

namespace App\Http\Middleware\Analytics;

use App\Services\Analytics\ArchiveFreshnessService;
use App\Services\Analytics\ArchiveOnViewService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 집계 화면 요청 앞에서 오래된 집계를 다시 계산하고, 응답에 신선도를 싣는 미들웨어
 *
 * 집계 표를 읽는 조회 라우트에만 붙인다(개요·추이·패턴·봇·리포트·한 항목 추이). 원장을 직접 읽는
 * 화면(실시간·방문자·유입 주소)은 집계가 필요 없고, 공개 방문자 카운터는 누구나 부르므로 붙이지 않는다.
 * 권한 미들웨어 **뒤**에 둔다 — 권한 없는 요청이 집계를 일으키지 않게.
 */
class EnsureArchiveFresh
{
    /**
     * 미들웨어를 만듭니다.
     *
     * @param  ArchiveOnViewService  $onView  화면 열 때 집계
     * @param  ArchiveFreshnessService  $freshness  신선도 계산기
     */
    public function __construct(
        private readonly ArchiveOnViewService $onView,
        private readonly ArchiveFreshnessService $freshness,
    ) {}

    /**
     * 집계를 필요하면 다시 계산한 뒤 요청을 넘기고, 성공 JSON 응답에 `data.freshness` 를 붙입니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $outcome = $this->onView->ensureFresh();

        $response = $next($request);

        // CSV 내려받기(스트림)·오류 응답은 건드리지 않는다.
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        try {
            $payload = $response->getData(true);

            if (! is_array($payload) || ($payload['success'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
                return $response;
            }

            $payload['data']['freshness'] = $this->freshness->describe($outcome);

            return $response->setData($payload);
        } catch (Throwable $e) {
            // 신선도는 안내일 뿐이다 — 계산이 실패해도 숫자는 나가야 한다.
            Log::warning('집계 신선도 계산 실패 — 응답은 신선도 없이 나갑니다', ['error' => $e->getMessage()]);

            return $response;
        }
    }
}
