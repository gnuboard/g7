<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;

/**
 * 방문자 통계 전체 삭제
 *
 * 쌓인 방문 기록과 집계를 모두 지웁니다. 되돌릴 수 없습니다 — 운영자가 「처음부터
 * 다시 모으겠다」고 정했을 때만 부릅니다.
 *
 * 표를 통째로 비우는(TRUNCATE) 대신 행을 지웁니다. TRUNCATE 는 트랜잭션 밖에서
 * 즉시 확정되므로 테스트가 자기 변경만 되돌리는 격리를 깨뜨립니다.
 */
class PurgeService
{
    /**
     * 한 번에 지울 건수
     */
    private const CHUNK_SIZE = 1000;

    /**
     * 삭제기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  ConversionRepositoryInterface  $conversions  전환 저장소
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  BotHitRepositoryInterface  $botHits  봇 기록 저장소
     * @param  CollectFailureRepositoryInterface  $failures  적재 실패 저장소
     * @param  ArchiveRunRepositoryInterface  $runs  집계 실행 이력 저장소
     * @param  SaltRepositoryInterface  $salts  솔트 저장소
     * @param  ActionNameRepositoryInterface  $names  이름 사전 저장소
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
        private readonly ActionNameRepositoryInterface $names,
    ) {}

    /**
     * 방문자 통계 기록을 모두 지웁니다.
     *
     * @return array<string, int> 대상별 삭제 건수
     */
    public function purgeAll(): array
    {
        $far = AnalyticsClock::now()->addYears(100);

        // 확장이 통계용으로 둔 표를 **먼저** 지운다. 코어 원장은 수백만 행을 청크로 지우므로 한
        // 트랜잭션에 담지 않는다 — 대신 확장 단계를 앞에 두고 트랜잭션으로 묶어, 확장이 실패하면
        // 코어 행을 하나도 건드리기 전에 멈춘다(「모두 지웠다」는데 한쪽만 남는 일을 막는다).
        // 확장은 필터 `core.analytics.purge_all` 에서 자기 표를 비우고 `{식별자} => 건수` 를 더한다.
        $extensions = DB::transaction(
            fn () => HookManager::applyFilters('core.analytics.purge_all', []),
        );

        $counts = [
            'actions' => $this->actions->deleteOlderThanInBatches($far, self::CHUNK_SIZE),
            'conversions' => $this->conversions->deleteOlderThanInBatches($far, self::CHUNK_SIZE),
            'visits' => $this->visits->deleteAllInBatches(self::CHUNK_SIZE),
            'bot_hits' => $this->botHits->deleteOlderThanInBatches($far, self::CHUNK_SIZE),
            'collect_failures' => $this->failures->deleteOlderThanInBatches($far, self::CHUNK_SIZE),
            'archive_runs' => $this->runs->deleteOlderThanInBatches($far, self::CHUNK_SIZE),
            'salts' => $this->salts->deleteOlderThan($far),
            'stats' => $this->stats->deleteAllStats(),
            // 사전은 맨 마지막에 지운다 — 액션과 집계가 `action_name_id` 로 이름을
            // 참조하므로, 그것들을 먼저 치워야 남는 참조가 없다. 여기서 지우지 않으면
            // 「모두 삭제」 뒤에도 방문했던 주소·검색어·파일명이 그대로 남는다.
            'action_names' => $this->names->deleteAllInBatches(self::CHUNK_SIZE),
        ];

        foreach (is_array($extensions) ? $extensions : [] as $identifier => $count) {
            if (is_string($identifier) && $identifier !== '' && is_numeric($count)) {
                $counts['ext:'.$identifier] = (int) $count;
            }
        }

        HookManager::doAction('core.analytics.data_purged', ['counts' => $counts]);

        return $counts;
    }
}
