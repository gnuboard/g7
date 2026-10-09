<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\CollectFailureIndexRequest;
use App\Http\Requests\Admin\Analytics\DiagnosticsRequest;
use App\Services\Analytics\DiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * 방문자 통계 진단 API
 *
 * 「비콘은 정상 응답을 받는데 원장이 비어 있다」의 원인을 한 화면에 모읍니다. 수집
 * 실패는 전부 조용하므로, 물어볼 곳이 없으면 운영자는 기능이 고장 난 줄만 압니다.
 */
class DiagnosticsController extends AdminBaseController
{
    /**
     * 진단 컨트롤러를 생성합니다.
     *
     * @param  DiagnosticsService  $diagnostics  진단 서비스
     * @param  CollectFailureRepositoryInterface  $failures  적재 실패 저장소
     */
    public function __construct(
        private readonly DiagnosticsService $diagnostics,
        private readonly CollectFailureRepositoryInterface $failures,
    ) {
        parent::__construct();
    }

    /**
     * 진단 결과를 조회합니다.
     *
     * @param  DiagnosticsRequest  $request  진단 요청 (프록시 판정에 쓴다)
     * @return JsonResponse 진단 응답
     */
    public function index(DiagnosticsRequest $request): JsonResponse
    {
        return $this->success(
            'analytics.diagnostics.fetch_success',
            $this->diagnostics->diagnostics(AnalyticsConfig::fromSettings(), $request),
        );
    }

    /**
     * 적재 실패 목록을 조회합니다.
     *
     * @param  CollectFailureIndexRequest  $request  실패 목록 요청
     * @return JsonResponse 실패 목록 응답
     */
    public function failures(CollectFailureIndexRequest $request): JsonResponse
    {
        $paginator = $this->failures->getPaginated(
            array_filter(['reason' => $request->input('reason')]),
            (int) $request->input('per_page', 20),
        );

        // 주소는 현재 접속자 화면과 같은 경계로 가린다 — 가림 0 옥텟(기본값)이면 원래 주소라,
        // 조회 권한만으로 보이면 방문자 주소를 보는 우회로가 된다.
        $includeIp = (bool) $request->user()?->hasPermission('core.analytics.privacy');
        $maskOctets = AnalyticsConfig::fromSettings()->ipMaskOctets;

        return $this->success('analytics.collect_failures.fetch_success', [
            'data' => array_map(static function ($failure) use ($includeIp, $maskOctets): array {
                $row = $failure->toArray();

                if (! $includeIp) {
                    $row['ip'] = null;
                }

                // 그 주소가 지금 설정대로 가려진 값인지 — 설정 전에 남은 행은 원래 주소다(7.1.0 실측 O-E8).
                $row['ip_masked'] = IpAddress::isMaskedBy(is_string($row['ip'] ?? null) ? $row['ip'] : null, $maskOctets);

                // 실패 시각도 같은 화면의 다른 시각과 같은 기준이어야 한다(#682 S3 라운드 6).
                if (isset($row['occurred_at'])) {
                    $row['occurred_at'] = AnalyticsClock::toSiteDateTimeString(Carbon::parse($row['occurred_at']));
                }

                return $row;
            }, $paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
