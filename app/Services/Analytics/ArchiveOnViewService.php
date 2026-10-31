<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\OnViewOutcome;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 화면 열 때 집계
 *
 * 집계는 스케줄러가 매시 5분에 돌린다. 공유 호스팅·로컬 개발처럼 cron 이 없는 설치에서는 그 한 번이
 * 영영 오지 않아, 통계 화면 숫자가 한 시각에 멈춘 채 정상처럼 보인다. 관리자가 집계 화면을 열거나
 * 새로고침하면 마지막 집계가 1분 넘게 지났을 때 그 자리에서 오늘치를 다시 계산하고, 밀린 날도 확정한다.
 *
 * 이 경로는 GET 응답 앞에서 돈다 — 어떤 실패도 응답을 깨지 않는다. 결과는 `OnViewOutcome` 으로
 * 돌려주고 화면이 안내를 고른다.
 */
class ArchiveOnViewService
{
    /**
     * 다시 집계할 최소 간격 (초)
     *
     * 설정이 아니라 상수다 — 여러 관리자가 동시에 열어도 1분에 한 번만 돈다.
     */
    public const MIN_INTERVAL_SECONDS = 60;

    /**
     * 간격을 지키는 캐시 키
     */
    private const GATE_KEY = 'analytics:archive:on_view:gate';

    /**
     * 요청 하나가 집계에 쓸 수 있는 최소 실행 시간 (초)
     */
    private const MIN_TIME_LIMIT_SECONDS = 120;

    /**
     * 화면 열 때 집계를 만듭니다.
     *
     * @param  ArchiveService  $archive  집계 서비스
     * @param  ArchiveCatchUpService  $catchUp  밀린 날 따라잡기
     * @param  StaleRunService  $staleRuns  멈춘 실행 정리
     * @param  ArchiveRunRepositoryInterface  $runs  실행 이력 저장소
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     */
    public function __construct(
        private readonly ArchiveService $archive,
        private readonly ArchiveCatchUpService $catchUp,
        private readonly StaleRunService $staleRuns,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly VisitRepositoryInterface $visits,
    ) {}

    /**
     * 오래된 집계를 필요하면 다시 계산합니다.
     *
     * @return OnViewOutcome 이번 요청의 결과
     */
    public function ensureFresh(): OnViewOutcome
    {
        $config = AnalyticsConfig::fromSettings();
        $max = $config->archiveOnViewMaxVisits;

        if (! $config->archiveOnViewEnabled) {
            return new OnViewOutcome(OnViewOutcome::DISABLED, null, $max);
        }

        $todayVisits = null;

        try {
            $todayVisits = $this->visits->countOnDate(AnalyticsClock::today());

            // 방문이 많은 사이트는 요청 안에서 집계하면 응답이 끊긴다 — 스케줄러에 맡긴다.
            // 문보다 먼저 판정한다 — 문 뒤에 두면 1분 안의 다음 화면들이 「대기」로 답해
            // 상단 경고가 사라지고, 멈춘 숫자가 정상처럼 보인다.
            if ($todayVisits > $max) {
                return new OnViewOutcome(OnViewOutcome::TOO_MANY_VISITS, $todayVisits, $max);
            }

            // 문을 닫는다 — 동시에 들어온 요청 중 하나만 아래로 내려간다. 잠금을 풀지 않고
            // 만료(1분)에 맡기므로 그동안 들어온 요청은 모두 여기서 돌아간다. 원자적 획득이라
            // 관리자 여럿이 같은 순간에 열어도 한 번만 돈다.
            if (! Cache::lock(self::GATE_KEY, self::MIN_INTERVAL_SECONDS)->get()) {
                return new OnViewOutcome(OnViewOutcome::THROTTLED, $todayVisits, $max);
            }

            // 스케줄러·수동 실행이 방금 끝냈으면 다시 계산할 것이 없다.
            $last = $this->runs->findLastSuccessful(ArchiveRunType::Hour->value);

            if ($last?->finished_at !== null
                && $last->finished_at->greaterThan(AnalyticsClock::now()->subSeconds(self::MIN_INTERVAL_SECONDS))
            ) {
                return new OnViewOutcome(OnViewOutcome::FRESH, $todayVisits, $max);
            }

            $this->extendTimeLimit();

            // 워커가 죽어 「실행 중」으로 남은 행을 닫는다 — 예약 매시 실행만 하던 정리를 여기서도 한다.
            // 스케줄러가 없는 설치에서는 이 경로가 유일한 정리 기회다.
            $this->staleRuns->failStale();

            $run = $this->archive->archiveHour([
                'trigger' => 'on_view',
                'record_skipped' => false,
            ]);

            if ($run === null) {
                return new OnViewOutcome(OnViewOutcome::LOCKED, $todayVisits, $max);
            }

            $runs = [$run];

            // 오늘 방문을 뺀 나머지 예산 안에서 밀린 날을 확정한다.
            $dates = array_keys($this->catchUp->candidates(
                ArchiveCatchUpService::MAX_DAYS,
                max(0, $max - $todayVisits),
            ));

            if ($dates !== []) {
                $runs = array_merge($runs, $this->catchUp->finalize($dates, 'on_view', false));
            }

            $status = $this->valueOf($run->status) === ArchiveRunStatus::Failed->value
                ? OnViewOutcome::FAILED
                : OnViewOutcome::RAN;

            return new OnViewOutcome($status, $todayVisits, $max, $runs);
        } catch (Throwable $e) {
            Log::warning('화면 열 때 집계 실패 — 응답은 기존 집계로 나갑니다', [
                'error' => $e->getMessage(),
            ]);

            return new OnViewOutcome(OnViewOutcome::FAILED, $todayVisits, $max);
        }
    }

    /**
     * 이 요청의 실행 시간 한도를 집계에 필요한 만큼 늘립니다.
     *
     * @return void 없음
     */
    private function extendTimeLimit(): void
    {
        $current = (int) ini_get('max_execution_time');

        // 0 은 무제한이다 — 줄이지 않는다.
        if ($current === 0) {
            return;
        }

        @set_time_limit(max($current, self::MIN_TIME_LIMIT_SECONDS));
    }

    /**
     * 실행 상태에서 문자열 값을 꺼냅니다.
     *
     * @param  mixed  $status  실행 상태 (열거형 또는 문자열)
     * @return string 상태 문자열
     */
    private function valueOf(mixed $status): string
    {
        return $status instanceof ArchiveRunStatus ? $status->value : (string) $status;
    }
}
