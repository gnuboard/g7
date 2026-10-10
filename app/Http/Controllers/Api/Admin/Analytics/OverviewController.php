<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Enums\Analytics\StatsDimension;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\PeriodRequest;
use App\Services\Analytics\DiagnosticsService;
use App\Services\Analytics\OverviewService;
use App\Services\Analytics\PrivacyService;
use App\Services\Analytics\RealtimeService;
use App\Services\Analytics\ReportService;
use App\Services\Analytics\SeriesService;
use Illuminate\Http\JsonResponse;

/**
 * 방문자 통계 개요 API
 *
 * 통계 화면의 첫 장이 한 번에 받아 가는 응답입니다 — 네 숫자, 추이, 상위 세 목록,
 * 현재 접속자, 그리고 화면 위에 띄울 경고를 판단할 메타.
 *
 * 화면이 이걸 여섯 번 나눠 부르면 첫 화면에서 요청이 여섯 번 나갑니다.
 */
class OverviewController extends AdminBaseController
{
    /**
     * 개요 컨트롤러를 생성합니다.
     *
     * @param  OverviewService  $overview  개요 지표 서비스
     * @param  SeriesService  $series  시계열 서비스
     * @param  ReportService  $reports  상위 목록 서비스
     * @param  RealtimeService  $realtime  현재 접속자 서비스
     * @param  PrivacyService  $privacy  개인정보 처리기
     * @param  DiagnosticsService  $diagnostics  진단 서비스
     */
    public function __construct(
        private readonly OverviewService $overview,
        private readonly SeriesService $series,
        private readonly ReportService $reports,
        private readonly RealtimeService $realtime,
        private readonly PrivacyService $privacy,
        private readonly DiagnosticsService $diagnostics,
    ) {
        parent::__construct();
    }

    /**
     * 개요를 조회합니다.
     *
     * @param  PeriodRequest  $request  기간 요청
     * @return JsonResponse 개요 응답
     */
    public function index(PeriodRequest $request): JsonResponse
    {
        $config = AnalyticsConfig::fromSettings();
        [$from, $to] = $request->resolvedRange();
        $types = $request->visitorTypes();

        $series = $this->series->series($from, $to, $config, [
            'unit' => $request->input('unit', 'auto'),
            'visitor_types' => $types,
        ]);

        return $this->success('analytics.overview.fetch_success', [
            'kpi' => $this->overview->kpi($types),
            'series' => $series,
            'top' => [
                'pages' => $this->topRows(StatsDimension::PageUrl->value, $from, $to, $types, $config),
                'referrers' => $this->topRows(StatsDimension::ReferrerName->value, $from, $to, $types, $config),
                'countries' => $this->topRows(StatsDimension::Country->value, $from, $to, $types, $config),
            ],
            'realtime' => $this->realtime->snapshot($config, ['include_ip' => false]),
            'meta' => $this->meta($config, $from, $to),
        ]);
    }

    /**
     * 상위 목록 한 갈래를 가져옵니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  방문자 유형
     * @param  AnalyticsConfig  $config  설정
     * @return array<int, array<string, mixed>> 상위 목록
     */
    private function topRows(string $dimension, string $from, string $to, array $types, AnalyticsConfig $config): array
    {
        return $this->reports->report($dimension, $from, $to, [
            'visitor_types' => $types,
            'per_page' => $config->topNOverview,
        ])['rows'];
    }

    /**
     * 화면이 경고를 띄울지 판단할 메타를 만듭니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @return array<string, mixed> 메타
     */
    private function meta(AnalyticsConfig $config, string $from, string $to): array
    {
        $queue = $this->diagnostics->diagnostics($config)['queue'];

        return [
            'from' => $from,
            'to' => $to,
            'mode' => $config->privacyMode->value,
            // 동의 모드인데 동의를 물어볼 곳이 없으면 익명으로 모은다 — 운영자가 그
            // 사실을 모른 채 「동의 모드」라고 믿으면 안 된다.
            'consent_source' => $this->privacy->consentSource(),
            // 제공자를 고르지 못한 상태(ambiguous·missing)는 출처만으로 정상 연동과 구별되지 않는다.
            'consent_selection' => $this->privacy->consentSelection()->state,
            'collection_enabled' => $config->enabled,
            'geoip_enabled' => (bool) g7_core_settings('geoip.feature_enabled', false),
            'realtime_poll_seconds' => $config->realtimePollSeconds,
            // 큐가 밀리면 비콘은 정상 응답을 받고도 원장에 닿지 않는다.
            'queue_backlogged' => $queue['backlogged'],
            'queue_pending_jobs' => $queue['pending_jobs'],
        ];
    }
}
