<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\BucketLabeler;
use App\Analytics\DTO\DimensionDefinition;
use App\Analytics\NotificationCooldown;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\ArchiveSkipReason;
use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\Concerns\BroadcastsRunProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 방문자 통계 집계
 *
 * 원장(방문·액션·봇 기록)을 읽어 시간·일·차원 세 집계 표를 만듭니다. 화면은 원장을
 * 직접 읽지 않고 이 표만 봅니다 — 몇 달치 방문을 화면을 열 때마다 훑으면 느려지고,
 * 보존기간이 지나 원장을 지워도 과거 숫자는 남아야 하기 때문입니다.
 *
 * 집계는 「지우고 넣기」입니다. 갱신(upsert)으로 하면 다시 계산했을 때 순위에서 빠진
 * 라벨이 남아 합계가 실제보다 커집니다. 한 트랜잭션 안에서 지우고 넣으므로 화면은
 * 이전 숫자 아니면 새 숫자를 보고, 반쯤 처리된 숫자는 보지 않습니다.
 */
class ArchiveService
{
    use BroadcastsRunProgress;

    /**
     * 같은 기간을 두 번 집계하지 않도록 잡는 락의 유효 시간 (초)
     *
     * 스케줄과 수동 실행 버튼이 겹치거나 서버가 여러 대일 때를 막는다.
     */
    private const LOCK_SECONDS = 3600;

    /**
     * 진행률을 다시 적는 간격 (청크 수)
     *
     * 청크마다 UPDATE 를 날리면 집계보다 진행률 쓰기가 비싸진다.
     */
    private const PROGRESS_EVERY_CHUNKS = 1;

    /**
     * 진행률을 방송하는 관리자 채널
     *
     * 현재 접속자 방송(`analytics.realtime.updated`)과 같은 채널을 쓴다 — 채널 인가는
     * `core.analytics.read` 하나다. 진단 화면은 이 진행률 방송만 구독하고, 실시간 화면이 현재
     * 접속자 방송을 따로 구독한다.
     */
    public const PROGRESS_CHANNEL = 'core.admin.analytics';

    /**
     * 진행률 방송 이벤트 이름
     */
    public const PROGRESS_EVENT = 'analytics.archive.progress';

    /**
     * 봇 기록을 한 번에 읽을 건수
     *
     * 봇 기록은 방문보다 훨씬 가벼워 청크를 크게 잡아도 메모리를 적게 쓴다.
     */
    private const BOT_CHUNK_SIZE = 1000;

    /**
     * 코어 차원 중 하루 라벨 수에 상한을 두는 것
     *
     * 이벤트 이름과 검색 탭은 공개 수집 API 로 들어오는 자유 문자열이다 — 누구나 이름을 바꿔 가며
     * 보내면 하루치 집계 행이 끝없이 늘어난다. 방문 수가 많은 라벨부터 이 수만큼만 남긴다.
     *
     * @var array<string, int>
     */
    private const CORE_DIMENSION_CAPS = [
        'event_name' => 500,
        'search_tab' => 50,
    ];

    /**
     * 집계기를 생성합니다.
     *
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  ArchiveRunRepositoryInterface  $runs  실행 이력 저장소
     * @param  ActionNameRepositoryInterface  $names  이름 사전 저장소
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  BotHitRepositoryInterface  $botHits  봇 기록 저장소
     * @param  NotificationCooldown  $cooldown  알림 쿨다운
     * @param  ConversionRepositoryInterface  $conversions  전환 원장 저장소
     * @param  DimensionRegistryInterface  $extensionDimensions  확장 차원 등록부
     */
    public function __construct(
        private readonly StatsRepositoryInterface $stats,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly ActionNameRepositoryInterface $names,
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly BotHitRepositoryInterface $botHits,
        private readonly NotificationCooldown $cooldown,
        private readonly ConversionRepositoryInterface $conversions,
        private readonly DimensionRegistryInterface $extensionDimensions,
    ) {}

    /**
     * 한 날짜의 집계 잠금 키를 돌려줍니다.
     *
     * 실행 종류(시간 집계·일 집계·구간 재집계·화면 열 때 집계)를 키에 넣지 않는다 — 모두 같은 날의 일 집계
     * 행을 지우고 다시 쓰므로, 종류별 키였을 때는 같은 날의 시간 집계와 재집계가 서로를 「다른 집계」로 보고
     * 동시에 돌아 한쪽 결과를 덮을 수 있었다(7.1.0 전수 실측 O-EF1). 같은 날짜는 한 번에 하나만 돈다.
     *
     * @param  string  $date  집계 일자 (Y-m-d, 사이트 표준시 기준)
     * @return string 잠금 키
     */
    public static function lockKey(string $date): string
    {
        return 'analytics:archive:'.$date;
    }

    /**
     * 하루치를 집계합니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d, 사이트 표준시 기준)
     * @param  array<string, mixed>  $options  실행 옵션 (run_type, trigger, is_final, triggered_by, run_id, dry_run, record_skipped)
     * @return AnalyticsArchiveRun|null 실행 이력 (마른 실행이거나, `record_skipped=false` 인데 잠금을 못 잡았으면 null)
     */
    public function archiveDay(string $date, array $options = []): ?AnalyticsArchiveRun
    {
        $runType = $options['run_type'] ?? ArchiveRunType::Day->value;
        $dryRun = (bool) ($options['dry_run'] ?? false);

        [$periodStart, $periodEnd] = AnalyticsClock::dayRangeUtc($date);

        if ($dryRun) {
            $this->collect($date, $periodStart, $periodEnd, AnalyticsConfig::fromSettings(), null);

            return null;
        }

        $lock = Cache::lock(self::lockKey($date), self::LOCK_SECONDS);

        if (! $lock->get()) {
            // 화면 열 때 집계는 여러 관리자가 동시에 열면 잠금을 못 잡는 것이 정상이다 — 그때마다
            // 건너뜀 행을 남기면 실행 이력이 요청 수만큼 불어난다. 앞선 요청이 같은 날을 계산하고 있다.
            if (($options['record_skipped'] ?? true) === false) {
                return null;
            }

            return $this->recordSkipped($runType, $date, $options, ArchiveSkipReason::Locked->label());
        }

        $run = isset($options['run_id']) ? $this->runs->findById((int) $options['run_id']) : null;

        if ($run === null) {
            $run = $this->runs->create([
                'run_type' => $runType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'trigger' => $options['trigger'] ?? 'scheduled',
                'status' => ArchiveRunStatus::Running->value,
                'progress_percent' => 0,
                'started_at' => AnalyticsClock::now(),
                'triggered_by' => $options['triggered_by'] ?? null,
            ]);
        } else {
            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Running->value,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'started_at' => AnalyticsClock::now(),
            ]);
        }

        $this->broadcastRunProgress($run);

        try {
            $config = AnalyticsConfig::fromSettings();
            $result = $this->collect($date, $periodStart, $periodEnd, $config, $run);

            $isFinal = (bool) ($options['is_final'] ?? false);
            $archivedAt = AnalyticsClock::now()->format('Y-m-d H:i:s');

            // 확장 집계 기여는 동기 구독(`'sync' => true`)이어야 한다 — 큐로 가면 수집기가 복제돼
            // 확장이 넣은 행이 여기 돌아오지 않는다. 복제되면 수집기가 경고를 남긴다.
            $collector = new ArchiveCollector($date);
            HookManager::doAction('core.analytics.archive.day', [
                'date' => $date,
                'collector' => $collector,
                'is_final' => $isFinal,
                'run_type' => $runType,
            ]);

            $this->write($date, $result, $collector, $isFinal, $archivedAt);

            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Success->value,
                'processed_visits' => $result['processed_visits'],
                'processed_actions' => $result['processed_actions'],
                'chunks' => $result['chunks'],
                'progress_percent' => 100,
                'finished_at' => AnalyticsClock::now(),
            ]);

            $this->broadcastRunProgress($run);
        } catch (Throwable $e) {
            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Failed->value,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => AnalyticsClock::now(),
            ]);

            // 실패도 방송한다 — 화면이 「끝났다」는 신호를 못 받으면 진행률 바가 멈춘 채
            // 남고, 운영자는 워커가 죽은 것으로 오해한다.
            $this->broadcastRunProgress($run);

            Log::error('방문자 통계 집계 실패', [
                'date' => $date,
                'run_type' => $runType,
                'error' => $e->getMessage(),
            ]);

            // 실패는 매번 로그에 남기고 알림만 하루 한 번 보낸다 — 집계는 매시 돌아,
            // 원인이 고쳐지기 전까지 같은 실패 메일이 하루 스물네 통 간다.
            if ($this->cooldown->shouldNotify('archive_failed')) {
                HookManager::doAction('core.analytics.archive_failed', [
                    'run_type' => $runType,
                    'period' => $date,
                    'error' => $e->getMessage(),
                    'run_id' => $run->id,
                ]);
            }
        } finally {
            $lock->release();
        }

        return $run->refresh();
    }

    /**
     * 직전 시간과 오늘치를 함께 집계합니다.
     *
     * 오늘 숫자는 계속 변하므로 매시 다시 계산하고 확정하지 않습니다. 확정은 하루가
     * 끝난 뒤 한 번만 합니다.
     *
     * @param  array<string, mixed>  $options  실행 옵션
     * @return AnalyticsArchiveRun|null 실행 이력
     */
    public function archiveHour(array $options = []): ?AnalyticsArchiveRun
    {
        $today = AnalyticsClock::today();

        return $this->archiveDay($today, array_merge($options, [
            'run_type' => $options['run_type'] ?? ArchiveRunType::Hour->value,
            'is_final' => false,
        ]));
    }

    /**
     * 집계를 돌리지 않은 날을 건너뜀으로 실행 이력에 남깁니다.
     *
     * @param  string  $runType  실행 종류
     * @param  string  $date  집계 일자 (Y-m-d, 사이트 표준시 기준)
     * @param  array<string, mixed>  $options  실행 옵션 (trigger, triggered_by)
     * @param  string  $reason  건너뛴 이유
     * @return AnalyticsArchiveRun 건너뜀 실행 이력
     */
    private function recordSkipped(string $runType, string $date, array $options, string $reason): AnalyticsArchiveRun
    {
        [$periodStart, $periodEnd] = AnalyticsClock::dayRangeUtc($date);

        $run = $this->runs->create([
            'run_type' => $runType,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'trigger' => $options['trigger'] ?? 'scheduled',
            'status' => ArchiveRunStatus::Skipped->value,
            'progress_percent' => 0,
            'started_at' => AnalyticsClock::now(),
            'finished_at' => AnalyticsClock::now(),
            'error' => $reason,
            'triggered_by' => $options['triggered_by'] ?? null,
        ]);

        // 건너뜀도 종료 상태다 — 방송하지 않으면 소켓을 켠 사이트에서 화면의 `skipped` 분기가
        // 도달하지 못해, 전부 건너뛴 재집계는 시작 토스트만 남기고 이력 표가 그대로 선다
        // (#682 S3 라운드 3).
        $this->broadcastRunProgress($run);

        return $run;
    }

    /**
     * 기간을 하루씩 다시 집계합니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  실행 옵션 (force, trigger, triggered_by, run_id)
     * @return array<int, AnalyticsArchiveRun> 날짜별 실행 이력
     */
    public function archiveRange(string $from, string $to, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $today = AnalyticsClock::today();
        $runs = [];

        // 보존일에 걸린 날은 원장이 (일부라도) 이미 지워졌다. 그날을 다시 계산하면 빈 재료로
        // 영구 보존되는 일·차원 집계를 덮어써 과거 숫자가 되돌릴 수 없이 사라진다 — 확정 여부·
        // --force 와 무관하게 건너뛰고 이유를 실행 이력에 남긴다.
        $ledgerFloor = Carbon::parse($today)
            ->subDays(AnalyticsConfig::fromSettings()->effectiveRetentionDays())
            ->toDateString();

        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();

            if ($date <= $ledgerFloor) {
                $runs[] = $this->recordSkipped(
                    $options['run_type'] ?? ArchiveRunType::Reaggregate->value,
                    $date,
                    $options,
                    ArchiveSkipReason::BeyondRetention->label(),
                );
                $cursor->addDay();

                continue;
            }

            // 확정된 날은 다시 계산하지 않는다 — 집계 로직이 바뀌어 일부러 다시
            // 돌리는 경우만 --force 로 연다. 건너뛴 사실은 이력에 남긴다: 아무 행도
            // 남기지 않으면 화면이 「시작했습니다」만 띄운 채 결과가 비어, 돌다 만
            // 것인지 안 돌아도 되는 것인지 운영자가 가릴 수 없다(보존일 밖 분기와 같은 꼴).
            if (! $force && $this->isFinalized($date)) {
                $runs[] = $this->recordSkipped(
                    $options['run_type'] ?? ArchiveRunType::Reaggregate->value,
                    $date,
                    $options,
                    ArchiveSkipReason::Finalized->label(),
                );
                $cursor->addDay();

                continue;
            }

            $run = $this->archiveDay($date, array_merge($options, [
                'run_type' => $options['run_type'] ?? ArchiveRunType::Reaggregate->value,
                'is_final' => $date !== $today,
                'run_id' => null,
            ]));

            if ($run !== null) {
                $runs[] = $run;
            }

            $cursor->addDay();
        }

        return $runs;
    }

    /**
     * 하루치 원장을 훑어 집계 재료를 모읍니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d)
     * @param  Carbon  $periodStart  구간 시작 (UTC)
     * @param  Carbon  $periodEnd  구간 끝 (UTC)
     * @param  AnalyticsConfig  $config  설정
     * @param  AnalyticsArchiveRun|null  $run  진행률을 적을 실행 이력
     * @return array{daily: DailyAccumulator, hourly: HourlyAccumulator, dimension_caps: array<string, int>, pii_dimensions: array<int, string>, processed_visits: int, processed_actions: int, chunks: int} 일·시간 누적기와 차원 상한·PII 차원·처리량
     *                                                                                                                                                                                                       집계 재료
     */
    private function collect(
        string $date,
        Carbon $periodStart,
        Carbon $periodEnd,
        AnalyticsConfig $config,
        ?AnalyticsArchiveRun $run,
    ): array {
        $daily = new DailyAccumulator;
        $hourly = new HourlyAccumulator;

        // 개인정보(`pii`) 차원은 집계하지 않는다 — 집계 표는 영구 보존이고 라벨이 값 원문이라,
        // 정보주체 삭제·소급 익명화가 원장에서 지운 값이 여기 남아 조회 권한 계정에게 보인다.
        // 값은 원장에만 두어 보존 기간·삭제·익명화의 대상이 되게 한다(#682 S4 라운드 4).
        $piiDimensions = [];
        $extensionDimensions = [];

        foreach ($this->extensionDimensions->all() as $name => $definition) {
            if ($definition->pii) {
                $piiDimensions[] = $definition->statsDimension();
            } else {
                $extensionDimensions[$name] = $definition;
            }
        }

        $totalVisits = $this->visits->countOnDate($date);
        $processedVisits = 0;
        $processedActions = 0;
        $chunks = 0;

        $this->visits->chunkByDate($date, $config->archiveChunkSize, function ($visits) use (
            &$processedVisits,
            &$processedActions,
            &$chunks,
            $daily,
            $hourly,
            $run,
            $totalVisits,
            $extensionDimensions,
            $config,
        ) {
            $visitIds = $visits->pluck('id')->all();
            $actions = $this->actions->groupedForVisits($visitIds);

            // 전환 금액은 액션이 아니라 전환 원장에 있다 — 목표 차원에 매출 합계를
            // 실으려면 같은 방문 묶음의 전환을 함께 읽어야 한다(부록 D `revenue_sum`).
            $conversions = $this->conversions->findForVisits($visitIds)->groupBy('visit_id');

            $nameIds = [];
            foreach ($visits as $visit) {
                $nameIds[] = $visit->entry_action_name_id;
                $nameIds[] = $visit->exit_action_name_id;
            }
            foreach ($actions as $group) {
                foreach ($group as $action) {
                    $nameIds[] = $action->action_name_id;
                    $nameIds[] = $action->page_title_id;
                }
            }

            $nameMap = $this->names->findManyById($nameIds);

            foreach ($visits as $visit) {
                $visitActions = $actions->get($visit->id, collect());
                $this->accumulateVisit(
                    $visit,
                    $visitActions,
                    $nameMap,
                    $daily,
                    $hourly,
                    $conversions->get($visit->id, collect()),
                    $config,
                );

                $this->accumulateExtensionDimensions($visit, $visitActions, $extensionDimensions, $daily);

                $processedVisits++;
                $processedActions += $visitActions->count();
            }

            $chunks++;

            if ($run !== null && $chunks % self::PROGRESS_EVERY_CHUNKS === 0) {
                $this->runs->update($run, [
                    'processed_visits' => $processedVisits,
                    'processed_actions' => $processedActions,
                    'chunks' => $chunks,
                    'progress_percent' => $totalVisits > 0
                        ? (int) min(99, floor($processedVisits / $totalVisits * 100))
                        : 99,
                ]);

                $this->broadcastRunProgress($run);
            }
        });

        $this->accumulateCarriedOverActions($date, $periodStart, $periodEnd, $config, $hourly);

        $this->accumulateBots($periodStart, $periodEnd, $daily, $hourly);

        return [
            'daily' => $daily,
            'hourly' => $hourly,
            'dimension_caps' => array_merge(
                array_combine(
                    array_map(fn ($definition) => $definition->statsDimension(), $extensionDimensions),
                    array_map(fn ($definition) => $definition->maxCardinality, $extensionDimensions),
                ) ?: [],
                self::CORE_DIMENSION_CAPS,
            ),
            'pii_dimensions' => $piiDimensions,
            'processed_visits' => $processedVisits,
            'processed_actions' => $processedActions,
            'chunks' => $chunks,
        ];
    }

    /**
     * 전날 시작해 자정을 넘어온 방문의 이날 액션을 시간 표에 더합니다.
     *
     * 방문은 시작한 날에 속하므로 전날 집계가 그 방문을 읽는다. 그런데 전날 집계는 자기
     * 날의 24칸만 쓰므로 자정 뒤 액션은 어느 칸에도 들어가지 못한다. 이날 집계가 그
     * 액션만 자기 시각 칸에 더한다 — 방문 수·일 지표는 방문이 시작한 날의 몫이라 건드리지
     * 않는다(방문 시작은 전날 시간 칸에 이미 있다).
     *
     * @param  string  $date  집계 일자 (Y-m-d)
     * @param  Carbon  $periodStart  구간 시작 (UTC)
     * @param  Carbon  $periodEnd  구간 끝 (UTC, 제외)
     * @param  AnalyticsConfig  $config  설정
     * @param  HourlyAccumulator  $hourly  시간 누적기
     * @return void 없음
     */
    private function accumulateCarriedOverActions(
        string $date,
        Carbon $periodStart,
        Carbon $periodEnd,
        AnalyticsConfig $config,
        HourlyAccumulator $hourly,
    ): void {
        $this->visits->chunkCarriedIntoDate($date, $periodStart, $config->archiveChunkSize, function ($visits) use (
            $periodStart,
            $periodEnd,
            $hourly,
        ) {
            $actions = $this->actions->groupedForVisits($visits->pluck('id')->all());

            foreach ($visits as $visit) {
                $visitorType = $visit->visitor_type instanceof VisitorType
                    ? $visit->visitor_type->value
                    : (string) $visit->visitor_type;

                foreach ($actions->get($visit->id, collect()) as $action) {
                    if ($action->occurred_at === null
                        || $action->occurred_at->lt($periodStart)
                        || $action->occurred_at->gte($periodEnd)
                    ) {
                        continue;
                    }

                    $hourly->addAction(
                        AnalyticsClock::hourStartOf($action->occurred_at),
                        $visitorType,
                        (string) $visit->visitor_key,
                        $this->enumValue($action->action_type) === ActionType::Pageview->value,
                        (int) ($action->time_spent_seconds ?? 0),
                    );
                }
            }
        });
    }

    /**
     * 방문 하나와 그 액션들을 누적기에 더합니다.
     *
     * @param  AnalyticsVisit  $visit  방문
     * @param  Collection<int, AnalyticsAction>  $actions  방문의 액션 목록
     * @param  array<int, AnalyticsActionName>  $nameMap  이름 사전
     * @param  DailyAccumulator  $daily  일 누적기
     * @param  HourlyAccumulator  $hourly  시간 누적기
     * @param  Collection<int, AnalyticsConversion>|null  $conversions  방문의 전환 목록
     * @param  AnalyticsConfig|null  $config  설정 (구간 경계 — 없으면 기본 경계)
     * @return void 없음
     */
    private function accumulateVisit(
        AnalyticsVisit $visit,
        Collection $actions,
        array $nameMap,
        DailyAccumulator $daily,
        HourlyAccumulator $hourly,
        ?Collection $conversions = null,
        ?AnalyticsConfig $config = null,
    ): void {
        $visitorType = $visit->visitor_type instanceof VisitorType
            ? $visit->visitor_type->value
            : (string) $visit->visitor_type;
        $visitorKey = (string) $visit->visitor_key;
        $source = $visit->source instanceof VisitSource ? $visit->source->value : (string) $visit->source;
        $serverOnly = $source === VisitSource::Server->value;

        $visitMetrics = [
            'pageviews' => (int) $visit->pageviews_count,
            'actions' => (int) $visit->actions_count,
            'total_time_seconds' => (int) $visit->total_time_seconds,
            'is_bounce' => (bool) $visit->is_bounce,
            'server_only' => $serverOnly,
            'is_returning' => $visit->is_returning,
        ];

        $daily->addVisit($visitorType, $visitorKey, $visitMetrics);

        if ($visit->first_action_at !== null) {
            $hourly->addVisitStart(
                AnalyticsClock::hourStartOf($visit->first_action_at),
                $visitorType,
                array_merge($visitMetrics, ['visitor_key' => $visitorKey]),
            );
        }

        $this->accumulateVisitDimensions($visit, $nameMap, $visitorType, $visitorKey, $daily, $config);

        foreach ($actions as $action) {
            $this->accumulateAction($action, $visit, $nameMap, $visitorType, $visitorKey, $daily, $hourly);
        }

        $this->accumulateConversions($conversions, $visit, $visitorType, $daily);
    }

    /**
     * 전환을 목표 차원에 더합니다.
     *
     * 금액은 목표마다 따로 붙는다 — 방문 합계를 목표 행마다 복사하면 매출이 없는 목표에도
     * 금액이 붙고(오귀속), 같은 목표가 두 번 일어나면 같은 금액이 두 번 더해진다.
     * 원장이 `goal_key` 를 갖고 있으므로 그 키로 갈라 담는다.
     *
     * `extra` 는 정수 칸이라 소수점을 그대로 담으면 반올림으로 값이 샙니다. 100 을 곱해
     * 두고 읽는 쪽에서 되돌립니다 — `decimal(15,2)` 의 두 자리를 그대로 보존합니다.
     *
     * @param  Collection<int, AnalyticsConversion>|null  $conversions  이 방문의 전환 목록
     * @param  AnalyticsVisit  $visit  방문
     * @param  string  $visitorType  방문자 유형
     * @param  DailyAccumulator  $daily  일 누적기
     * @return void 없음
     */
    private function accumulateConversions(
        ?Collection $conversions,
        AnalyticsVisit $visit,
        string $visitorType,
        DailyAccumulator $daily,
    ): void {
        if ($conversions === null) {
            return;
        }

        $visitKey = (string) $visit->id;

        foreach ($conversions as $conversion) {
            $goalKey = (string) ($conversion->goal_key ?? '');

            if ($goalKey === '') {
                continue;
            }

            $metrics = [
                'visit_key' => $visitKey,
                'hits' => 1,
            ];

            $revenueCents = (int) round(((float) ($conversion->revenue ?? 0)) * 100);

            if ($revenueCents > 0) {
                $metrics['extra'] = ['revenue_cents' => $revenueCents];
            }

            $daily->addDimension(StatsDimension::Goal->value, $goalKey, $visitorType, $metrics);
        }
    }

    /**
     * 등록된 확장 차원 값을 `ext:{식별자}:{키}` 차원에 더합니다.
     *
     * 방문 범위 차원은 방문 행의 값을 방문 단위로(조회 = 그 방문의 페이지 수), 액션 범위
     * 차원은 그 값을 실은 액션마다 한 번씩 셉니다. 등록부에 없는 이름(확장이 비활성·제거된
     * 뒤 남은 값)은 집계하지 않습니다 — 원장에는 그대로 남아 확장이 돌아오면 다시 집계됩니다.
     *
     * @param  AnalyticsVisit  $visit  방문
     * @param  Collection<int, AnalyticsAction>  $actions  방문의 액션 목록
     * @param  array<string, DimensionDefinition>  $definitions  등록된 차원 (원장 이름 → 정의)
     * @param  DailyAccumulator  $daily  일 누적기
     * @return void 없음
     */
    private function accumulateExtensionDimensions(
        AnalyticsVisit $visit,
        Collection $actions,
        array $definitions,
        DailyAccumulator $daily,
    ): void {
        if ($definitions === []) {
            return;
        }

        $visitorType = $this->enumValue($visit->visitor_type) ?? VisitorType::Guest->value;
        $visitKey = (string) $visit->id;
        $bounce = $visit->is_bounce ? 1 : 0;

        foreach ((array) ($visit->custom_dimensions ?? []) as $name => $value) {
            $definition = $definitions[$name] ?? null;

            if ($definition === null || $definition->scope !== 'visit' || ! is_scalar($value) || (string) $value === '') {
                continue;
            }

            $daily->addDimension($definition->statsDimension(), (string) $value, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => (int) $visit->pageviews_count,
                'bounces' => $bounce,
                'total_time_seconds' => (int) $visit->total_time_seconds,
            ]);
        }

        foreach ($actions as $action) {
            foreach ((array) ($action->custom_dimensions ?? []) as $name => $value) {
                $definition = $definitions[$name] ?? null;

                if ($definition === null || $definition->scope !== 'action' || ! is_scalar($value) || (string) $value === '') {
                    continue;
                }

                $daily->addDimension($definition->statsDimension(), (string) $value, $visitorType, [
                    'visit_key' => $visitKey,
                    'hits' => 1,
                    'total_time_seconds' => (int) ($action->time_spent_seconds ?? 0),
                ]);
            }
        }
    }

    /**
     * 방문 속성에서 나오는 차원을 더합니다.
     *
     * @param  AnalyticsVisit  $visit  방문
     * @param  array<int, AnalyticsActionName>  $nameMap  이름 사전
     * @param  string  $visitorType  방문자 유형
     * @param  string  $visitorKey  방문자 키
     * @param  DailyAccumulator  $daily  일 누적기
     * @param  AnalyticsConfig|null  $config  설정 (구간 경계 — 없으면 기본 경계)
     * @return void 없음
     */
    private function accumulateVisitDimensions(
        AnalyticsVisit $visit,
        array $nameMap,
        string $visitorType,
        string $visitorKey,
        DailyAccumulator $daily,
        ?AnalyticsConfig $config = null,
    ): void {
        $visitKey = (string) $visit->id;
        $bounce = $visit->is_bounce ? 1 : 0;
        $time = (int) $visit->total_time_seconds;
        // 방문 차원의 「조회」는 그 방문들이 본 페이지 수다. 방문마다 1 로 적으면 리포트의 「방문당 페이지」가
        // 어느 브라우저·국가·유입이든 늘 1 이 된다.
        $pageviews = (int) $visit->pageviews_count;

        $entryLabel = $this->nameLabel($nameMap, $visit->entry_action_name_id);
        $exitLabel = $this->nameLabel($nameMap, $visit->exit_action_name_id);

        if ($entryLabel !== null) {
            $daily->addDimension(StatsDimension::EntryPage->value, $entryLabel, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => $pageviews,
                'entries' => 1,
                'bounces' => $bounce,
                'total_time_seconds' => $time,
                'action_name_id' => $visit->entry_action_name_id,
            ]);
        }

        if ($exitLabel !== null) {
            $daily->addDimension(StatsDimension::ExitPage->value, $exitLabel, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => $pageviews,
                'exits' => 1,
                'bounces' => $bounce,
                'total_time_seconds' => $time,
                'action_name_id' => $visit->exit_action_name_id,
            ]);
        }

        $simple = [
            StatsDimension::ReferrerType->value => $this->enumValue($visit->referrer_type),
            StatsDimension::ReferrerName->value => $visit->referrer_name,
            StatsDimension::ReferrerKeyword->value => $visit->referrer_keyword,
            StatsDimension::Campaign->value => $visit->campaign_name,
            StatsDimension::CampaignSource->value => $visit->campaign_source,
            StatsDimension::CampaignMedium->value => $visit->campaign_medium,
            StatsDimension::Browser->value => $visit->browser_name,
            StatsDimension::BrowserVersion->value => $this->joinLabel($visit->browser_name, $visit->browser_version),
            StatsDimension::Os->value => $visit->os_name,
            StatsDimension::DeviceType->value => $this->enumValue($visit->device_type),
            StatsDimension::Resolution->value => $visit->screen_resolution,
            StatsDimension::BrowserLanguage->value => $visit->browser_language,
            StatsDimension::Locale->value => $visit->locale,
            StatsDimension::Country->value => $visit->country_code,
            StatsDimension::Region->value => $this->joinLabel($visit->country_code, $visit->region_code, '/'),
            StatsDimension::City->value => $this->joinLabel($visit->country_code, $visit->city, '/'),
            // 체류는 모든 방문을 센다. 경계는 설정에서 바꿀 수 있고 바꾼 뒤 집계부터 새 라벨로 쌓인다.
            StatsDimension::VisitDuration->value => BucketLabeler::duration(
                $time,
                $config?->durationBucketBounds ?? AnalyticsConfig::parseBounds(AnalyticsConfig::DEFAULT_DURATION_BUCKET_BOUNDS, ''),
            ),
            // 본 페이지 수는 페이지를 한 번이라도 본 방문만 센다 — 내려받기만 한 방문(0페이지)이 「1페이지」
            // 구간을 부풀리지 않게.
            StatsDimension::VisitPages->value => $pageviews >= 1
                ? BucketLabeler::pages(
                    $pageviews,
                    $config?->pagesBucketBounds ?? AnalyticsConfig::parseBounds(AnalyticsConfig::DEFAULT_PAGES_BUCKET_BOUNDS, ''),
                )
                : null,
        ];

        foreach ($simple as $dimension => $label) {
            if ($label === null || $label === '') {
                continue;
            }

            $daily->addDimension($dimension, (string) $label, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => $pageviews,
                'bounces' => $bounce,
                'total_time_seconds' => $time,
            ]);
        }
    }

    /**
     * 액션 하나에서 나오는 차원과 시간 지표를 더합니다.
     *
     * @param  AnalyticsAction  $action  액션
     * @param  AnalyticsVisit  $visit  방문
     * @param  array<int, AnalyticsActionName>  $nameMap  이름 사전
     * @param  string  $visitorType  방문자 유형
     * @param  string  $visitorKey  방문자 키
     * @param  DailyAccumulator  $daily  일 누적기
     * @param  HourlyAccumulator  $hourly  시간 누적기
     * @return void 없음
     */
    private function accumulateAction(
        AnalyticsAction $action,
        AnalyticsVisit $visit,
        array $nameMap,
        string $visitorType,
        string $visitorKey,
        DailyAccumulator $daily,
        HourlyAccumulator $hourly,
    ): void {
        $actionType = $this->enumValue($action->action_type);
        $isPageview = $actionType === ActionType::Pageview->value;
        $timeSpent = (int) ($action->time_spent_seconds ?? 0);

        if ($action->occurred_at !== null) {
            $hourly->addAction(
                AnalyticsClock::hourStartOf($action->occurred_at),
                $visitorType,
                $visitorKey,
                $isPageview,
                $timeSpent,
            );
        }

        $label = $this->nameLabel($nameMap, $action->action_name_id);

        if ($label === null) {
            return;
        }

        $visitKey = (string) $visit->id;
        $dimension = match ($actionType) {
            ActionType::Pageview->value => StatsDimension::PageUrl->value,
            ActionType::Download->value => StatsDimension::Download->value,
            ActionType::Outlink->value => StatsDimension::Outlink->value,
            ActionType::SiteSearch->value => StatsDimension::SiteSearch->value,
            // 이벤트는 이름을 사전의 `event` 종류로 저장한 행만 센다. 이름을 저장하기 전의 옛 이벤트 행은
            // 주소(`url` 종류)를 가리켜, 세면 이벤트 목록에 주소가 섞인다.
            ActionType::Event->value => ($nameMap[$action->action_name_id]->type ?? null) === 'event'
                ? StatsDimension::EventName->value
                : null,
            // 목표 차원은 액션이 아니라 전환 원장이 채운다 — 액션 이름은 목표가 일어난
            // 주소라서, 그것으로 행을 만들면 목표별이 아니라 주소별로 갈린다.
            default => null,
        };

        if ($dimension === null) {
            return;
        }

        // 페이지 차원의 이탈은 그 페이지 하나만 보고 떠난 방문이다 — 이탈 방문은 페이지뷰가 하나뿐이라 그
        // 페이지에만 1 이 붙는다. 더하지 않으면 페이지·제목 리포트의 이탈률이 늘 0% 다.
        $pageBounce = $isPageview && $visit->is_bounce ? 1 : 0;

        $metrics = [
            'visit_key' => $visitKey,
            'hits' => 1,
            'bounces' => $pageBounce,
            'total_time_seconds' => $timeSpent,
            'action_name_id' => $action->action_name_id,
        ];

        // 검색 결과가 0건인 검색어는 따로 센다 — 운영자가 「찾았는데 없는 것」을
        // 콘텐츠로 메울 수 있게 하는 값이다.
        if ($dimension === StatsDimension::SiteSearch->value && (int) ($action->search_result_count ?? 0) === 0) {
            $metrics['extra'] = ['no_result' => 1];
        }

        $daily->addDimension($dimension, $label, $visitorType, $metrics);

        // 검색은 어느 탭(전체·게시글·상품…)에서 했는지도 따로 센다 — 검색어 행은 그대로 둔다.
        if ($dimension === StatsDimension::SiteSearch->value && is_string($action->search_tab) && $action->search_tab !== '') {
            $daily->addDimension(StatsDimension::SearchTab->value, $action->search_tab, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => 1,
            ]);
        }

        if ($isPageview) {
            $titleLabel = $this->nameLabel($nameMap, $action->page_title_id);

            if ($titleLabel !== null) {
                $daily->addDimension(StatsDimension::PageTitle->value, $titleLabel, $visitorType, [
                    'visit_key' => $visitKey,
                    'hits' => 1,
                    'bounces' => $pageBounce,
                    'total_time_seconds' => $timeSpent,
                    'action_name_id' => $action->page_title_id,
                ]);
            }
        }
    }

    /**
     * 봇 요청을 집계에 더합니다.
     *
     * 봇은 방문을 만들지 않고 요청 단위로만 남으므로, 방문 수는 「봇 이름 × IP」 조합
     * 수로 봅니다.
     *
     * @param  Carbon  $periodStart  구간 시작 (UTC)
     * @param  Carbon  $periodEnd  구간 끝 (UTC)
     * @param  DailyAccumulator  $daily  일 누적기
     * @param  HourlyAccumulator  $hourly  시간 누적기
     * @return void 없음
     */
    private function accumulateBots(
        Carbon $periodStart,
        Carbon $periodEnd,
        DailyAccumulator $daily,
        HourlyAccumulator $hourly,
    ): void {
        $botType = VisitorType::Bot->value;
        $visitKeys = [];
        $firstHourOf = [];

        $this->botHits->chunkBetween($periodStart, $periodEnd, self::BOT_CHUNK_SIZE, function ($hits) use ($daily, $hourly, $botType, &$visitKeys, &$firstHourOf) {
            foreach ($hits as $hit) {
                $botName = (string) $hit->bot_name;
                $identity = $botName.'|'.($hit->getRawOriginal('ip') ?? '');
                $visitKeys[$identity] = ($visitKeys[$identity] ?? 0) + 1;

                $hourStart = AnalyticsClock::hourStartOf($hit->occurred_at);

                // 방문 수는 「봇 이름 × IP」 조합을 1건으로 세므로, 그 조합이 처음
                // 나타난 시각에만 방문 시작을 올린다. 액션만 올리면 시간 표의 방문
                // 수가 0 으로 남아 같은 데이터인데 일 표와 수가 어긋난다.
                if (! isset($firstHourOf[$identity])) {
                    $firstHourOf[$identity] = $hourStart;
                    $hourly->addVisitStart($hourStart, $botType);
                }

                $hourly->addAction(
                    $hourStart,
                    $botType,
                    $identity,
                    true,
                );

                $daily->addDimension(StatsDimension::BotName->value, $botName, $botType, [
                    'visit_key' => $identity,
                    'hits' => 1,
                ]);

                $daily->addDimension(StatsDimension::BotPage->value, (string) $hit->url, $botType, [
                    'visit_key' => $identity,
                    'hits' => 1,
                ]);
            }
        });

        // 봇은 방문이라는 행이 없으므로 「봇 이름 × IP」 하나를 방문 1건으로 보고,
        // 그 조합이 가져간 페이지 수를 그 방문의 페이지뷰로 센다.
        foreach ($visitKeys as $identity => $hits) {
            $daily->addVisit($botType, $identity, ['pageviews' => $hits, 'actions' => $hits]);
        }
    }

    /**
     * 모은 재료를 집계 표에 씁니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d)
     * @param  array<string, mixed>  $result  집계 재료
     * @param  ArchiveCollector  $collector  확장 차원 수집기
     * @param  bool  $isFinal  확정 여부
     * @param  string  $archivedAt  집계 수행 시각
     * @return void 없음
     */
    private function write(
        string $date,
        array $result,
        ArchiveCollector $collector,
        bool $isFinal,
        string $archivedAt,
    ): void {
        /** @var DailyAccumulator $daily */
        $daily = $result['daily'];
        /** @var HourlyAccumulator $hourly */
        $hourly = $result['hourly'];
        $caps = $result['dimension_caps'] ?? [];
        $piiDimensions = $result['pii_dimensions'] ?? [];

        // 세 표를 한 트랜잭션으로 바꾼다 — 일 숫자는 새것인데 차원 목록은 옛것인
        // 순간을 화면이 보지 않게 한다.
        DB::transaction(function () use ($date, $daily, $hourly, $collector, $isFinal, $archivedAt, $caps, $piiDimensions): void {
            $this->stats->replaceDaily($date, $daily->dailyRows($date, $isFinal, $archivedAt));

            // 그날 집계에 한 건도 없던 시각의 옛 행도 지워야 하므로, 재료에 있는
            // 시각이 아니라 하루 24칸을 모두 훑는다.
            foreach ($this->hourSlots($date) as $hourStart) {
                $this->stats->replaceHourly($hourStart, $hourly->hourlyRows($hourStart, $archivedAt));
            }

            $coreRows = $daily->dimensionRows($date, $archivedAt, $caps);
            $dimensionRows = array_merge(
                $coreRows,
                $this->stampCollectorRows(
                    $this->withoutCoreDuplicates($this->withoutPiiDimensions($collector->rows(), $piiDimensions), $coreRows),
                    $archivedAt,
                ),
            );

            $this->stats->replaceDimensions($date, $dimensionRows);
        });
    }

    /**
     * 개인정보(`pii`)로 선언된 차원의 확장 행을 뺍니다.
     *
     * 원장에서 모으는 경로와 같은 규칙이다 — 확장이 수집기로 직접 넘긴 값도 집계 표에
     * 남으면 정보주체 삭제·소급 익명화가 닿지 않는다.
     *
     * @param  array<int, array<string, mixed>>  $rows  확장 차원 행
     * @param  array<int, string>  $piiDimensions  개인정보 차원 이름(`ext:{식별자}:{키}`)
     * @return array<int, array<string, mixed>> 개인정보 차원이 아닌 확장 행
     */
    private function withoutPiiDimensions(array $rows, array $piiDimensions): array
    {
        if ($piiDimensions === []) {
            return $rows;
        }

        $pii = array_flip($piiDimensions);
        $dropped = [];

        $kept = array_values(array_filter($rows, static function (array $row) use ($pii, &$dropped): bool {
            if (! isset($pii[$row['dimension']])) {
                return true;
            }

            $dropped[$row['dimension']] = true;

            return false;
        }));

        if ($dropped !== []) {
            Log::warning('개인정보 차원은 집계하지 않아 확장 집계 행을 버렸습니다.', [
                'dimensions' => array_keys($dropped),
            ]);
        }

        return $kept;
    }

    /**
     * 코어가 집계한 행과 겹치는 확장 행을 뺍니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  확장 차원 행
     * @param  array<int, array<string, mixed>>  $coreRows  코어 차원 행
     * @return array<int, array<string, mixed>> 겹치지 않는 확장 행
     */
    private function withoutCoreDuplicates(array $rows, array $coreRows): array
    {
        $taken = [];

        foreach ($coreRows as $row) {
            $taken[$row['visitor_type']."\0".$row['dimension']."\0".$row['label_hash']] = true;
        }

        return array_values(array_filter($rows, static function (array $row) use ($taken): bool {
            if (! isset($taken[$row['visitor_type']."\0".$row['dimension']."\0".$row['label_hash']])) {
                return true;
            }

            // 등록된 차원은 코어가 원장에서 이미 집계했다 — 같은 라벨을 확장이 또 넘기면
            // 유일키에 걸려 그날 집계 전체가 실패하므로 코어 행을 남긴다.
            Log::warning('확장 집계 행이 코어가 집계한 행과 겹쳐 버렸습니다.', [
                'dimension' => $row['dimension'],
                'label' => mb_substr((string) $row['label'], 0, 80),
                'visitor_type' => $row['visitor_type'],
            ]);

            return false;
        }));
    }

    /**
     * 확장이 모은 행에 집계 시각을 채웁니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  확장 차원 행
     * @param  string  $archivedAt  집계 수행 시각
     * @return array<int, array<string, mixed>> 시각이 채워진 행
     */
    private function stampCollectorRows(array $rows, string $archivedAt): array
    {
        foreach ($rows as &$row) {
            $row['archived_at'] = $archivedAt;
        }

        return $rows;
    }

    /**
     * 하루의 시각 칸 목록을 만듭니다.
     *
     * 서머타임 전환일은 23칸이나 25칸이 되므로 24를 그대로 쓰지 않고 실제 시각을
     * 따라갑니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @return array<int, string> 시각 목록 (Y-m-d H:00:00)
     */
    private function hourSlots(string $date): array
    {
        $timezone = AnalyticsClock::siteTimezone();
        $cursor = Carbon::parse($date.' 00:00:00', $timezone);
        $end = Carbon::parse($date.' 00:00:00', $timezone)->addDay();
        $slots = [];

        while ($cursor->lessThan($end)) {
            $slots[] = $cursor->format('Y-m-d H:00:00');
            $cursor->addHour();
        }

        return $slots;
    }

    /**
     * 그 날 집계가 확정됐는지 확인합니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @return bool 확정된 행이 하나라도 있으면 true
     */
    private function isFinalized(string $date): bool
    {
        return $this->stats->isDailyFinal($date);
    }

    /**
     * 이름 사전에서 라벨을 꺼냅니다.
     *
     * @param  array<int, AnalyticsActionName>  $nameMap  이름 사전
     * @param  int|null  $id  사전 ID
     * @return string|null 라벨 (없으면 null)
     */
    private function nameLabel(array $nameMap, ?int $id): ?string
    {
        if ($id === null || ! isset($nameMap[$id])) {
            return null;
        }

        $name = (string) $nameMap[$id]->name;

        return $name === '' ? null : $name;
    }

    /**
     * Enum 이거나 문자열인 값에서 문자열을 꺼냅니다.
     *
     * @param  mixed  $value  대상 값
     * @return string|null 문자열 값
     */
    private function enumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * 두 값을 구분자로 잇습니다 (둘 다 있어야 합니다).
     *
     * @param  string|null  $first  앞 값
     * @param  string|null  $second  뒤 값
     * @param  string  $separator  구분자
     * @return string|null 이어 붙인 라벨
     */
    private function joinLabel(?string $first, ?string $second, string $separator = ' '): ?string
    {
        if ($first === null || $first === '' || $second === null || $second === '') {
            return null;
        }

        return $first.$separator.$second;
    }
}
