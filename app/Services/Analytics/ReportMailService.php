<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use Illuminate\Support\Carbon;

/**
 * 정기 리포트 발송
 *
 * 지난 한 주·한 달의 방문 요약을 만들어 알림 훅으로 밀어 올립니다. 메일을 직접
 * 보내지 않고 훅만 발화하므로, 수신자·채널·문구는 코어 알림 정의가 소유합니다.
 *
 * 설정 토글과 알림 정의의 활성 상태가 **둘 다** 참일 때만 나갑니다. 둘 중 하나로만
 * 막으면 운영자가 끈 곳과 실제로 나가는 곳이 어긋납니다.
 */
class ReportMailService
{
    /**
     * 리포트에 담을 상위 항목 수
     */
    private const TOP_LIMIT = 5;

    /**
     * 리포트 생성기를 만듭니다.
     *
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     */
    public function __construct(
        private readonly StatsRepositoryInterface $stats,
    ) {}

    /**
     * 기간 리포트를 만들어 훅을 발화합니다.
     *
     * @param  string  $period  기간 (weekly, monthly)
     * @param  AnalyticsConfig  $config  설정
     * @return array<string, mixed>|null 리포트 내용 (보내지 않았으면 null)
     */
    public function send(string $period, AnalyticsConfig $config): ?array
    {
        if (! $this->isEnabled($period, $config)) {
            return null;
        }

        [$from, $to] = $this->periodRange($period);
        $report = $this->build($period, $from, $to);

        HookManager::doAction('core.analytics.'.$period.'_report_ready', $report);

        return $report;
    }

    /**
     * 기간 리포트 내용을 만듭니다.
     *
     * @param  string  $period  기간 (weekly, monthly)
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @return array<string, mixed> 리포트 내용
     */
    public function build(string $period, string $from, string $to): array
    {
        $types = $this->humanTypes();

        $current = $this->totals($from, $to, $types);
        [$previousFrom, $previousTo] = $this->previousRange($from, $to);
        $previous = $this->totals($previousFrom, $previousTo, $types);

        return [
            'period' => $period,
            'period_label' => $from.' ~ '.$to,
            'from' => $from,
            'to' => $to,
            'visits' => $current['visits'],
            'visitors' => $current['visitors'],
            'pageviews' => $current['pageviews'],
            'change_pct' => $this->changePercent($previous['visits'], $current['visits']),
            'top_pages' => $this->top(StatsDimension::PageUrl->value, $from, $to, $types),
            'top_referrers' => $this->top(StatsDimension::ReferrerName->value, $from, $to, $types),
        ];
    }

    /**
     * 그 기간 리포트가 켜져 있는지 확인합니다.
     *
     * @param  string  $period  기간 (weekly, monthly)
     * @param  AnalyticsConfig  $config  설정
     * @return bool 보낼 수 있으면 true
     */
    private function isEnabled(string $period, AnalyticsConfig $config): bool
    {
        return match ($period) {
            'weekly' => $config->reportWeeklyEnabled,
            'monthly' => $config->reportMonthlyEnabled,
            default => false,
        };
    }

    /**
     * 기간 이름으로 시작·종료일을 만듭니다.
     *
     * 리포트는 지난 기간을 돌아보는 것이라, 오늘이 아니라 **직전** 주·달을 봅니다.
     *
     * @param  string  $period  기간 (weekly, monthly)
     * @return array{0: string, 1: string} 시작일과 종료일 (Y-m-d)
     */
    public function periodRange(string $period): array
    {
        $today = Carbon::parse(AnalyticsClock::today());

        if ($period === 'monthly') {
            $start = $today->copy()->subMonthNoOverflow()->startOfMonth();

            return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        }

        $start = $today->copy()->subWeek()->startOfWeek();

        return [$start->toDateString(), $start->copy()->endOfWeek()->toDateString()];
    }

    /**
     * 같은 길이의 직전 기간을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @return array{0: string, 1: string} 직전 기간의 시작일과 종료일
     */
    private function previousRange(string $from, string $to): array
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        $days = $start->diffInDays($end) + 1;

        return [
            $start->copy()->subDays($days)->toDateString(),
            $start->copy()->subDay()->toDateString(),
        ];
    }

    /**
     * 기간의 방문·방문자·페이지뷰 합을 구합니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @return array{visits: int, visitors: int, pageviews: int} 합계
     */
    private function totals(string $from, string $to, array $types): array
    {
        $rows = $this->stats->daily($from, $to, $types);

        return [
            'visits' => (int) $rows->sum('visits'),
            // 방문자 키는 날마다 바뀔 수 있어 여러 날에 걸친 진짜 순방문자는 셀 수
            // 없다. 그래서 「일별 순방문자의 합」으로 정의하고 화면에도 그렇게 적는다.
            'visitors' => (int) $rows->sum('visitors'),
            'pageviews' => (int) $rows->sum('pageviews'),
        ];
    }

    /**
     * 차원 상위 목록을 구합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @return array<int, array{label: string, visits: int}> 상위 목록
     */
    private function top(string $dimension, string $from, string $to, array $types): array
    {
        return $this->stats->dimension($dimension, $from, $to, [
            'visitor_types' => $types,
            'limit' => self::TOP_LIMIT,
        ])->map(fn ($row): array => [
            'label' => (string) $row->label,
            'visits' => (int) $row->visits,
        ])->all();
    }

    /**
     * 증감률을 구합니다.
     *
     * 이전 값이 0 이면 비율이 성립하지 않으므로 빈 값을 돌려줍니다 — 0 에서 10 으로
     * 늘어난 것을 「무한 % 증가」로 적으면 읽는 사람이 오해합니다.
     *
     * @param  int  $previous  직전 기간 값
     * @param  int  $current  이번 기간 값
     * @return float|null 증감률 (%)
     */
    private function changePercent(int $previous, int $current): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * 사람 방문자 유형 목록을 돌려줍니다.
     *
     * @return array<int, string> 유형 목록 (봇 제외)
     */
    private function humanTypes(): array
    {
        return [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value];
    }
}
