<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\Analytics\CloudIpRangeService;
use Illuminate\Http\JsonResponse;

/**
 * 클라우드 IP 대역 목록 API
 *
 * 설정 화면의 「지금 갱신」 버튼이 부릅니다. 주 1회 예약이 돌지만, 차단을 막 켠
 * 운영자는 목록이 채워지기를 일주일 기다릴 수 없습니다.
 */
class CloudRangeController extends AdminBaseController
{
    /**
     * 대역 목록 컨트롤러를 생성합니다.
     *
     * @param  CloudIpRangeService  $ranges  대역 목록 서비스
     */
    public function __construct(
        private readonly CloudIpRangeService $ranges,
    ) {
        parent::__construct();
    }

    /**
     * 목록을 지금 갱신합니다.
     *
     * @return JsonResponse 갱신 응답
     */
    public function refresh(): JsonResponse
    {
        $result = $this->ranges->refresh();

        if ($result['count'] === 0) {
            // 받아 온 것이 없으면 기존 파일은 그대로 둔다 — 새 목록이 없다고 옛 목록을
            // 버리면 그 순간부터 모든 데이터센터 요청이 사람으로 집계된다.
            return $this->error('analytics.cloud_ranges.refresh_failed', 502);
        }

        // 갱신 시각은 설정·진단 응답과 **같은 기준**으로 내보낸다 — 오프셋을 실으면 화면의
        // `| datetime` 이 브라우저 시간대로 다시 옮긴다(#682 S3 라운드 7).
        $result['updated_at'] = AnalyticsClock::storedToSiteDateTimeString($result['updated_at'] ?? null);

        return $this->success('analytics.cloud_ranges.refresh_success', $result);
    }
}
