<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Enums\Analytics\StatsDimension;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\PeriodRequest;
use App\Http\Requests\Admin\Analytics\ReportRequest;
use App\Services\Analytics\CsvExporter;
use App\Services\Analytics\PatternService;
use App\Services\Analytics\ReportService;
use App\Services\Analytics\SeriesService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 방문자 통계 리포트 API
 *
 * 인기 페이지·유입처·브라우저·국가 — 화면은 여럿이지만 서버에서는 차원 이름 하나만
 * 다른 같은 요청입니다. 추이와 패턴도 같은 기간 규약을 씁니다.
 */
class ReportController extends AdminBaseController
{
    /**
     * 리포트 컨트롤러를 생성합니다.
     *
     * @param  ReportService  $reports  상위 목록 서비스
     * @param  SeriesService  $series  시계열 서비스
     * @param  PatternService  $patterns  이용 패턴 서비스
     * @param  CsvExporter  $csv  CSV 내보내기
     */
    public function __construct(
        private readonly ReportService $reports,
        private readonly SeriesService $series,
        private readonly PatternService $patterns,
        private readonly CsvExporter $csv,
    ) {
        parent::__construct();
    }

    /**
     * 상위 목록을 조회합니다.
     *
     * @param  ReportRequest  $request  리포트 요청
     * @param  string  $report  리포트 이름
     * @return JsonResponse|StreamedResponse 목록 응답 또는 CSV 내려받기
     */
    public function show(ReportRequest $request, string $report): JsonResponse|StreamedResponse
    {
        if (! $this->reports->isValidReport($report)) {
            return $this->error('validation.analytics.report_unknown', 404);
        }

        [$from, $to] = $request->resolvedRange();

        $options = [
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 25),
            'sort' => $request->input('sort'),
            'search' => $request->input('q'),
        ];

        // 봇 차원은 봇 유형 행에만 있다 — 사람 유형 목록을 넘기면 언제나 비어 있으므로,
        // 운영자가 제외 토글을 직접 건드렸을 때만 그 뜻을 따른다.
        if ($request->has('exclude')) {
            $options['visitor_types'] = $request->visitorTypes();
        }

        if ($request->wantsCsv()) {
            // 내려받기는 쪽을 나누지 않는다 — 운영자가 받으려는 것은 화면에 보이던
            // 25행이 아니라 그 기간 전체다.
            return $this->exportCsv($report, $from, $to, array_merge($options, [
                'page' => 1,
                'per_page' => 100,
            ]));
        }

        $result = $this->reports->report($report, $from, $to, $options);

        return $this->success('analytics.report.fetch_success', [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'data' => $result['rows'],
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'has_more' => $result['has_more'],
            ],
        ]);
    }

    /**
     * 추이 시계열을 조회합니다.
     *
     * @param  PeriodRequest  $request  기간 요청
     * @return JsonResponse 시계열 응답
     */
    public function series(PeriodRequest $request): JsonResponse
    {
        [$from, $to] = $request->resolvedRange();

        return $this->success('analytics.series.fetch_success', $this->series->series(
            $from,
            $to,
            AnalyticsConfig::fromSettings(),
            [
                'unit' => $request->input('unit', 'auto'),
                'visitor_types' => $request->visitorTypes(),
            ],
        ));
    }

    /**
     * 요일·시간대 이용 패턴을 조회합니다.
     *
     * @param  PeriodRequest  $request  기간 요청
     * @return JsonResponse 패턴 응답
     */
    public function patterns(PeriodRequest $request): JsonResponse
    {
        [$from, $to] = $request->resolvedRange();

        return $this->success('analytics.patterns.fetch_success', $this->patterns->patterns(
            $from,
            $to,
            AnalyticsConfig::fromSettings(),
            ['visitor_types' => $request->visitorTypes()],
        ));
    }

    /**
     * 봇 방문 리포트를 조회합니다.
     *
     * @param  ReportRequest  $request  리포트 요청
     * @return JsonResponse|StreamedResponse 목록 응답
     */
    public function bots(ReportRequest $request): JsonResponse|StreamedResponse
    {
        $response = $this->show($request, StatsDimension::BotName->value);

        // CSV 는 집계 표를 받으러 오는 길이라 원장 목록을 끼워 넣지 않는다.
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        // 「어느 봇이 얼마나」 옆에 「무엇을 언제」를 붙인다 — 집계만으로는 어떤 주소를
        // 긁어 갔는지 알 수 없어 운영자가 차단 여부를 판단할 근거가 없다.
        $payload['data']['recent_hits'] = $this->reports->recentBotHits();

        return $response->setData($payload);
    }

    /**
     * 상위 목록을 CSV 로 흘려보냅니다.
     *
     * @param  string  $report  리포트 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  조회 옵션
     * @return StreamedResponse 내려받기 응답
     */
    private function exportCsv(string $report, string $from, string $to, array $options): StreamedResponse
    {
        $rows = function () use ($report, $from, $to, $options): iterable {
            $page = 1;

            do {
                $result = $this->reports->report($report, $from, $to, array_merge($options, ['page' => $page]));

                foreach ($result['rows'] as $row) {
                    yield [
                        $row['label'],
                        $row['label_display'],
                        $row['visits'],
                        $row['hits'],
                        $row['entries'],
                        $row['exits'],
                        $row['bounce_rate'],
                        $row['avg_time'],
                        $row['pages_per_visit'],
                    ];
                }

                $page++;
            } while ($result['has_more']);
        };

        return $this->csv->stream(
            sprintf('analytics-%s-%s_%s.csv', $report, $from, $to),
            ['label', 'label_display', 'visits', 'hits', 'entries', 'exits', 'bounce_rate', 'avg_time_seconds', 'pages_per_visit'],
            $rows(),
        );
    }
}
