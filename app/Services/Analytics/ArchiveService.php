<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\NotificationCooldown;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\ArchiveRunRepositoryInterface;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsActionName;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsVisit;
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
     * 봇 기록을 한 번에 읽을 건수
     *
     * 봇 기록은 방문보다 훨씬 가벼워 청크를 크게 잡아도 메모리를 적게 쓴다.
     */
    private const BOT_CHUNK_SIZE = 1000;

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
     */
    public function __construct(
        private readonly StatsRepositoryInterface $stats,
        private readonly ArchiveRunRepositoryInterface $runs,
        private readonly ActionNameRepositoryInterface $names,
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly BotHitRepositoryInterface $botHits,
        private readonly NotificationCooldown $cooldown,
    ) {}

    /**
     * 하루치를 집계합니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d, 사이트 표준시 기준)
     * @param  array<string, mixed>  $options  실행 옵션 (run_type, trigger, is_final, triggered_by, run_id, dry_run)
     * @return AnalyticsArchiveRun|null 실행 이력 (마른 실행이면 null)
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

        $lock = Cache::lock('analytics:archive:'.$runType.':'.$date, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->runs->create([
                'run_type' => $runType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'trigger' => $options['trigger'] ?? 'scheduled',
                'status' => ArchiveRunStatus::Skipped->value,
                'progress_percent' => 0,
                'started_at' => AnalyticsClock::now(),
                'finished_at' => AnalyticsClock::now(),
                'error' => __('analytics.archive.skipped_locked'),
                'triggered_by' => $options['triggered_by'] ?? null,
            ]);
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

        try {
            $config = AnalyticsConfig::fromSettings();
            $result = $this->collect($date, $periodStart, $periodEnd, $config, $run);

            $isFinal = (bool) ($options['is_final'] ?? false);
            $archivedAt = AnalyticsClock::now()->format('Y-m-d H:i:s');

            $collector = new ArchiveCollector($date);
            HookManager::doAction('core.analytics.archive.day', ['date' => $date, 'collector' => $collector]);

            $this->write($date, $result, $collector, $isFinal, $archivedAt);

            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Success->value,
                'processed_visits' => $result['processed_visits'],
                'processed_actions' => $result['processed_actions'],
                'chunks' => $result['chunks'],
                'progress_percent' => 100,
                'finished_at' => AnalyticsClock::now(),
            ]);
        } catch (Throwable $e) {
            $this->runs->update($run, [
                'status' => ArchiveRunStatus::Failed->value,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => AnalyticsClock::now(),
            ]);

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

        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();

            // 확정된 날은 다시 계산하지 않는다 — 집계 로직이 바뀌어 일부러 다시
            // 돌리는 경우만 --force 로 연다.
            if (! $force && $this->isFinalized($date)) {
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
     * @return array{daily: DailyAccumulator, hourly: HourlyAccumulator, processed_visits: int, processed_actions: int, chunks: int}
     *                                                                                                                               집계 재료
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
        ) {
            $visitIds = $visits->pluck('id')->all();
            $actions = $this->actions->groupedForVisits($visitIds);

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
                $this->accumulateVisit($visit, $visitActions, $nameMap, $daily, $hourly);

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
            }
        });

        $this->accumulateBots($periodStart, $periodEnd, $daily, $hourly);

        return [
            'daily' => $daily,
            'hourly' => $hourly,
            'processed_visits' => $processedVisits,
            'processed_actions' => $processedActions,
            'chunks' => $chunks,
        ];
    }

    /**
     * 방문 하나와 그 액션들을 누적기에 더합니다.
     *
     * @param  AnalyticsVisit  $visit  방문
     * @param  Collection<int, AnalyticsAction>  $actions  방문의 액션 목록
     * @param  array<int, AnalyticsActionName>  $nameMap  이름 사전
     * @param  DailyAccumulator  $daily  일 누적기
     * @param  HourlyAccumulator  $hourly  시간 누적기
     * @return void 없음
     */
    private function accumulateVisit(
        AnalyticsVisit $visit,
        Collection $actions,
        array $nameMap,
        DailyAccumulator $daily,
        HourlyAccumulator $hourly,
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

        $this->accumulateVisitDimensions($visit, $nameMap, $visitorType, $visitorKey, $daily);

        foreach ($actions as $action) {
            $this->accumulateAction($action, $visit, $nameMap, $visitorType, $visitorKey, $daily, $hourly);
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
     * @return void 없음
     */
    private function accumulateVisitDimensions(
        AnalyticsVisit $visit,
        array $nameMap,
        string $visitorType,
        string $visitorKey,
        DailyAccumulator $daily,
    ): void {
        $visitKey = (string) $visit->id;
        $bounce = $visit->is_bounce ? 1 : 0;
        $time = (int) $visit->total_time_seconds;

        $entryLabel = $this->nameLabel($nameMap, $visit->entry_action_name_id);
        $exitLabel = $this->nameLabel($nameMap, $visit->exit_action_name_id);

        if ($entryLabel !== null) {
            $daily->addDimension(StatsDimension::EntryPage->value, $entryLabel, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => 1,
                'entries' => 1,
                'bounces' => $bounce,
                'total_time_seconds' => $time,
                'action_name_id' => $visit->entry_action_name_id,
            ]);
        }

        if ($exitLabel !== null) {
            $daily->addDimension(StatsDimension::ExitPage->value, $exitLabel, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => 1,
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
        ];

        foreach ($simple as $dimension => $label) {
            if ($label === null || $label === '') {
                continue;
            }

            $daily->addDimension($dimension, (string) $label, $visitorType, [
                'visit_key' => $visitKey,
                'hits' => 1,
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
            ActionType::Goal->value => StatsDimension::Goal->value,
            default => null,
        };

        if ($dimension === null) {
            return;
        }

        $metrics = [
            'visit_key' => $visitKey,
            'hits' => 1,
            'total_time_seconds' => $timeSpent,
            'action_name_id' => $action->action_name_id,
        ];

        // 검색 결과가 0건인 검색어는 따로 센다 — 운영자가 「찾았는데 없는 것」을
        // 콘텐츠로 메울 수 있게 하는 값이다.
        if ($dimension === StatsDimension::SiteSearch->value && (int) ($action->search_result_count ?? 0) === 0) {
            $metrics['extra'] = ['no_result' => 1];
        }

        $daily->addDimension($dimension, $label, $visitorType, $metrics);

        if ($isPageview) {
            $titleLabel = $this->nameLabel($nameMap, $action->page_title_id);

            if ($titleLabel !== null) {
                $daily->addDimension(StatsDimension::PageTitle->value, $titleLabel, $visitorType, [
                    'visit_key' => $visitKey,
                    'hits' => 1,
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

        $this->botHits->chunkBetween($periodStart, $periodEnd, self::BOT_CHUNK_SIZE, function ($hits) use ($daily, $hourly, $botType, &$visitKeys) {
            foreach ($hits as $hit) {
                $botName = (string) $hit->bot_name;
                $identity = $botName.'|'.($hit->getRawOriginal('ip') ?? '');
                $visitKeys[$identity] = ($visitKeys[$identity] ?? 0) + 1;

                $hourly->addAction(
                    AnalyticsClock::hourStartOf($hit->occurred_at),
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

        // 세 표를 한 트랜잭션으로 바꾼다 — 일 숫자는 새것인데 차원 목록은 옛것인
        // 순간을 화면이 보지 않게 한다.
        DB::transaction(function () use ($date, $daily, $hourly, $collector, $isFinal, $archivedAt): void {
            $this->stats->replaceDaily($date, $daily->dailyRows($date, $isFinal, $archivedAt));

            // 그날 집계에 한 건도 없던 시각의 옛 행도 지워야 하므로, 재료에 있는
            // 시각이 아니라 하루 24칸을 모두 훑는다.
            foreach ($this->hourSlots($date) as $hourStart) {
                $this->stats->replaceHourly($hourStart, $hourly->hourlyRows($hourStart, $archivedAt));
            }

            $dimensionRows = array_merge(
                $daily->dimensionRows($date, $archivedAt),
                $this->stampCollectorRows($collector->rows(), $archivedAt),
            );

            $this->stats->replaceDimensions($date, $dimensionRows);
        });
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
