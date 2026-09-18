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
     * 차원별 CSV 부가 열 — 헤더 이름 => `extra` 안의 키.
     *
     * 집계가 `extra` 에 담아 두고 화면이 읽는 값이다. 여기 없는 차원은 공통 9열만 낸다.
     *
     * @var array<string, array<string, string>>
     */
    private const EXTRA_CSV_COLUMNS = [
        'site_search' => ['no_result_count' => 'no_result'],
        'goal' => ['revenue_sum' => 'revenue_cents'],
    ];

    /**
     * 센트로 담아 둔 뒤 되돌려 써야 하는 부가 열 — 헤더 이름 기준.
     *
     * 집계의 `extra` 는 정수 칸이라 금액을 센트로 담는다. 내보낼 때 100 으로 나눠
     * 사람이 쓰는 단위로 돌린다.
     *
     * @var array<int, string>
     */
    private const CENT_CSV_COLUMNS = ['revenue_sum'];

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

        // 한 쪽에 몇 행을 보일지는 운영자가 설정에서 정한다 — 여기에 숫자를 박아 두면
        // 설정 화면에서 바꿔도 목록이 그대로여서, 저장은 됐는데 안 먹는 설정이 된다.
        $options = [
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', AnalyticsConfig::fromSettings()->topNDetail),
            'sort' => $request->input('sort'),
            'search' => $request->input('q'),
        ];

        // 봇 차원은 봇 유형 행에만 있다 — 사람 유형 목록을 넘기면 언제나 비어 있으므로,
        // 운영자가 제외 토글을 직접 건드렸을 때만 그 뜻을 따른다. 제외 토글은 「관리자·회원
        // 방문을 빼라」는 뜻이라 봇 목록에는 뺄 것이 없다 — 통계 화면에서 켠 토글이 기간
        // 쿼리와 함께 봇 화면으로 따라와도 목록이 비면 안 된다.
        if ($request->has('exclude') && ! $this->isBotDimension($report)) {
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
     * @return JsonResponse|StreamedResponse 시계열 응답 또는 CSV 내려받기
     */
    public function series(PeriodRequest $request): JsonResponse|StreamedResponse
    {
        [$from, $to] = $request->resolvedRange();

        $series = $this->series->series(
            $from,
            $to,
            AnalyticsConfig::fromSettings(),
            [
                'unit' => $request->input('unit', 'auto'),
                'visitor_types' => $request->visitorTypes(),
            ],
        );

        if ($request->wantsCsv()) {
            return $this->exportSeriesCsv($series, $from, $to);
        }

        return $this->success('analytics.series.fetch_success', $series);
    }

    /**
     * 추이 시계열을 CSV 로 내보냅니다.
     *
     * 화면의 그래프를 표로 받는 길입니다 — 운영자가 스프레드시트에서 자기 방식대로
     * 다시 그릴 수 있어야 합니다(부록 D).
     *
     * @param  array{unit: string, unit_downgraded: bool, points: array<int, array<string, mixed>>}  $series  시계열
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @return StreamedResponse 내려받기 응답
     */
    private function exportSeriesCsv(array $series, string $from, string $to): StreamedResponse
    {
        $rows = function () use ($series): iterable {
            foreach ($series['points'] as $point) {
                yield [
                    $point['ts'],
                    $point['visits'] ?? 0,
                    $point['visitors'] ?? 0,
                    $point['pageviews'] ?? 0,
                    $point['bounce_rate'] ?? 0,
                    $point['avg_time'] ?? 0,
                    $point['pages_per_visit'] ?? 0,
                ];
            }
        };

        return $this->csv->stream(
            sprintf('analytics-series-%s_%s.csv', $from, $to),
            ['ts', 'visits', 'visitors', 'pageviews', 'bounce_rate', 'avg_time_seconds', 'pages_per_visit'],
            $rows(),
        );
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
     * 봇 유형 행만 가진 차원인지 판정합니다.
     *
     * @param  string  $report  리포트 이름(별칭 포함)
     * @return bool 봇 차원이면 true
     */
    private function isBotDimension(string $report): bool
    {
        return in_array(
            $this->reports->dimensionFor($report),
            [StatsDimension::BotName->value, StatsDimension::BotPage->value],
            true,
        );
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
        // 차원마다 화면에서 보이던 부가 열이 있다. 그 열이 CSV 에서 빠지면 운영자는
        // 내려받은 파일이 틀린 것인지 화면이 틀린 것인지 판단할 근거가 없다.
        // 별칭(`goals` → `goal`)을 먼저 풀어야 별칭 주소로 받은 파일에서만 열이 빠지지 않는다.
        $extraColumns = self::EXTRA_CSV_COLUMNS[$this->reports->dimensionFor($report)] ?? [];

        $rows = function () use ($report, $from, $to, $options, $extraColumns): iterable {
            $page = 1;

            do {
                $result = $this->reports->report($report, $from, $to, array_merge($options, ['page' => $page]));

                foreach ($result['rows'] as $row) {
                    $line = [
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

                    foreach ($extraColumns as $header => $extraKey) {
                        $value = (int) ($row['extra'][$extraKey] ?? 0);

                        $line[] = in_array($header, self::CENT_CSV_COLUMNS, true)
                            ? number_format($value / 100, 2, '.', '')
                            : $value;
                    }

                    yield $line;
                }

                $page++;
            } while ($result['has_more']);
        };

        return $this->csv->stream(
            sprintf('analytics-%s-%s_%s.csv', $report, $from, $to),
            array_merge(
                ['label', 'label_display', 'visits', 'hits', 'entries', 'exits', 'bounce_rate', 'avg_time_seconds', 'pages_per_visit'],
                array_keys($extraColumns),
            ),
            $rows(),
        );
    }
}
