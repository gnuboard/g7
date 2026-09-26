<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Support\Carbon;

/**
 * 방문자 통계 보존 정리
 *
 * 보존 기간이 지난 원장과 부속 기록을 지웁니다. 집계 표(일·차원)는 지우지 않습니다 —
 * 원장이 사라져도 과거 숫자는 남아야 하기 때문입니다.
 *
 * 보존 기간의 하한은 이 서비스가 소유합니다. 커맨드 옵션이나 과거 설정으로 0 이
 * 들어오면 오늘 방문까지 지워집니다.
 */
class PruneService
{
    /**
     * 보존 기간 하한 (일)
     *
     * 하루 이틀치만 남기면 집계가 한 번 밀렸을 때 원장이 먼저 사라져 그 기간의
     * 숫자를 영영 만들 수 없습니다.
     */
    public const MINIMUM_RETENTION_DAYS = 7;

    /**
     * 솔트 보존 일수의 하한
     *
     * 정보주체 요청은 오늘·어제 솔트로 익명 기록을 찾습니다(`DataSubjectService`). 설정으로
     * 이보다 짧게 두면 어제 기록을 주소로 찾을 수 없게 되므로 서비스가 자릅니다.
     */
    public const MINIMUM_SALT_RETENTION_DAYS = 2;

    /**
     * 정리기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  ConversionRepositoryInterface  $conversions  전환 저장소
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  BotHitRepositoryInterface  $botHits  봇 기록 저장소
     * @param  CollectFailureRepositoryInterface  $failures  적재 실패 저장소
     * @param  ArchiveRunRepositoryInterface  $runs  집계 실행 이력 저장소
     * @param  SaltRepositoryInterface  $salts  솔트 저장소
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly ConversionRepositoryInterface $conversions,
        private readonly StatsRepositoryInterface $stats,
        private readonly BotHitRepositoryInterface $botHits,
        private readonly CollectFailureRepositoryInterface $failures,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly SaltRepositoryInterface $salts,
    ) {}

    /**
     * 보존 기간이 지난 기록을 지웁니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @param  int|null  $daysOverride  보존일 재정의 (커맨드 옵션)
     * @param  bool  $dryRun  참이면 건수만 세고 지우지 않습니다
     * @return array<string, int> 대상별 건수
     */
    public function prune(AnalyticsConfig $config, ?int $daysOverride = null, bool $dryRun = false): array
    {
        $days = max(self::MINIMUM_RETENTION_DAYS, $daysOverride ?? $config->retentionDays);
        $now = AnalyticsClock::now();
        $ledgerBefore = $now->copy()->subDays($days);

        HookManager::doAction('core.analytics.before_prune', ['days' => $days, 'dry_run' => $dryRun]);

        if ($dryRun) {
            $counts = $this->countTargets($config, $ledgerBefore, $now);

            HookManager::doAction('core.analytics.after_prune', [
                'days' => $days,
                'counts' => $counts,
                'dry_run' => true,
            ]);

            return $counts;
        }

        // 액션·전환을 먼저 지우고 방문을 지운다 — 외래키 연쇄 삭제에 기대지 않고
        // 순서를 코드가 소유한다(연쇄가 없으면 방문만 사라지고 액션이 고아가 된다).
        $counts = [
            'actions' => $this->actions->deleteOlderThanInBatches($ledgerBefore, $this->chunkSize()),
            'conversions' => $this->conversions->deleteOlderThanInBatches($ledgerBefore, $this->chunkSize()),
            'visits' => $this->visits->deleteOlderThanInBatches($ledgerBefore, $this->chunkSize()),
            'stats_hourly' => $this->stats->deleteHourlyOlderThanInBatches(
                $now->copy()->subDays(max(1, $config->hourlyRetentionDays)),
                $this->chunkSize(),
            ),
            'bot_hits' => $this->botHits->deleteOlderThanInBatches(
                $now->copy()->subDays(max(1, $config->botHitsRetentionDays)),
                $this->chunkSize(),
            ),
            'collect_failures' => $this->failures->deleteOlderThanInBatches(
                $now->copy()->subDays(max(1, $config->failureLogRetentionDays)),
                $this->chunkSize(),
            ),
            'archive_runs' => $this->runs->deleteOlderThanInBatches(
                $now->copy()->subDays(max(1, $config->failureLogRetentionDays)),
                $this->chunkSize(),
            ),
            'salts' => $this->salts->deleteOlderThan($now->copy()->subDays($this->saltRetentionDays())),
        ];

        // 이름 사전은 지우지 않는다 — 집계 행이 이 사전을 가리키고 있어, 고아로 보이는
        // 행을 지우면 과거 리포트의 라벨이 사라진다.

        HookManager::doAction('core.analytics.after_prune', [
            'days' => $days,
            'counts' => $counts,
            'dry_run' => false,
        ]);

        return $counts;
    }

    /**
     * 지우지 않고 대상 건수만 셉니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @param  Carbon  $ledgerBefore  원장 기준 시각
     * @param  Carbon  $now  현재 시각
     * @return array<string, int> 대상별 건수
     */
    private function countTargets(AnalyticsConfig $config, Carbon $ledgerBefore, Carbon $now): array
    {
        return [
            'actions' => $this->actions->countOlderThan($ledgerBefore),
            'conversions' => $this->conversions->countOlderThan($ledgerBefore),
            'visits' => $this->visits->countOlderThan($ledgerBefore),
            'stats_hourly' => $this->stats->countHourlyOlderThan(
                $now->copy()->subDays(max(1, $config->hourlyRetentionDays)),
            ),
            'bot_hits' => $this->botHits->countOlderThan(
                $now->copy()->subDays(max(1, $config->botHitsRetentionDays)),
            ),
            'collect_failures' => $this->failures->countOlderThan(
                $now->copy()->subDays(max(1, $config->failureLogRetentionDays)),
            ),
            'archive_runs' => $this->runs->countOlderThan(
                $now->copy()->subDays(max(1, $config->failureLogRetentionDays)),
            ),
            'salts' => $this->salts->countOlderThan($now->copy()->subDays($this->saltRetentionDays())),
        ];
    }

    /**
     * 한 번에 지울 건수를 반환합니다.
     *
     * 값은 `config/analytics.php` `limits.prune_chunk_size` 가 정하고, 너무 작거나 크면
     * 잠금 시간·왕복 횟수가 나빠지므로 100~10,000 으로 자릅니다.
     *
     * @return int 청크 크기
     */
    private function chunkSize(): int
    {
        return max(100, min(10000, (int) config('analytics.limits.prune_chunk_size', 1000)));
    }

    /**
     * 솔트를 남겨 두는 일수를 반환합니다.
     *
     * 값은 `config/analytics.php` `limits.salt_retention_days` 가 정합니다. 솔트가 지워진 날의
     * 익명 기록은 주소로 찾을 수 없으므로 정보주체 조회 창보다 짧아지지 않게 합니다.
     *
     * @return int 보존 일수
     */
    private function saltRetentionDays(): int
    {
        return max(self::MINIMUM_SALT_RETENTION_DAYS, (int) config('analytics.limits.salt_retention_days', 5));
    }
}
