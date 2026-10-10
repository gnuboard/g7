<?php

namespace App\Http\Controllers\Api\Public;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Extension\CacheInterface;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Services\Analytics\OverviewService;
use Illuminate\Http\JsonResponse;

/**
 * 공개 방문자 요약 API
 *
 * 사용자 화면의 방문자 카운터가 읽는 값입니다 — 오늘·어제·최대·전체 네 숫자뿐이고,
 * 개인을 가리키는 것은 하나도 나가지 않습니다. 그누보드5 의 사이드바 카운터와 같은
 * 정의입니다.
 *
 * 로그인 없이 누구나 부를 수 있으므로 짧게 캐시합니다 — 사람이 많이 오는 사이트에서
 * 모든 방문이 이 질의를 한 번씩 돌리면 카운터가 사이트를 느리게 만듭니다.
 */
class AnalyticsSummaryController extends PublicBaseController
{
    /**
     * 요약을 다시 세기까지 기다리는 시간 (초)
     */
    private const CACHE_SECONDS = 60;

    /**
     * 공개 요약 컨트롤러를 생성합니다.
     *
     * @param  OverviewService  $overview  개요 지표 서비스
     * @param  CacheInterface  $cache  요약 캐시
     */
    public function __construct(
        private readonly OverviewService $overview,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct();
    }

    /**
     * 공개 방문자 요약을 조회합니다.
     *
     * @return JsonResponse 요약 응답 (공개가 꺼져 있으면 404)
     */
    public function index(): JsonResponse
    {
        $config = AnalyticsConfig::fromSettings();

        if (! $config->publicSummaryEnabled) {
            // 끈 기능은 「값이 0」이 아니라 「없는 것」으로 답한다 — 위젯이 0 을 보고
            // 사이트에 아무도 오지 않았다고 그리면 안 된다.
            return $this->error('analytics.summary.disabled', 404);
        }

        $summary = $this->cache->remember(
            'analytics:public-summary:'.AnalyticsClock::today(),
            fn (): array => $this->overview->publicSummary($config),
            self::CACHE_SECONDS,
        );

        return $this->success('analytics.summary.fetch_success', $summary);
    }
}
