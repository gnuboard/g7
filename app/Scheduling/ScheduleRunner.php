<?php

namespace App\Scheduling;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Exceptions\ScheduleExecutionException;
use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Models\ScheduleHistory;
use App\Scheduling\Exceptions\ScheduleCommandExtractionException;
use App\Services\Schedule\ScheduleRegistrar;
use App\Services\Schedule\ScheduleRunContext;
use App\Support\ExecutionEnvironment;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * In-process 스케줄 러너 — `schedule:run` 의 무CLI 대체 (A-5).
 *
 * 진입점은 둘(웹 크론 엔드포인트 · `g7:schedule-run` 커맨드)이고 본체는 이 클래스
 * 하나다. 서버 크론이 있는 환경에서도 같은 본체를 쓴다.
 *
 * `schedule:run` 을 그대로 부를 수 없는 이유는 등록된 이벤트의 다수가 shell 형태이기
 * 때문이다 — 프레임워크는 그것을 `php artisan …` 프로세스로 스폰하는데, CLI 를 쓸 수
 * 없는 호스팅에서는 그 스폰이 성립하지 않는다. 이 러너는 그 문자열에서 커맨드를
 * 되뽑아(ArtisanCommandExtractor) 같은 프로세스 안에서 실행한다.
 *
 * 세 가지 규약을 지킨다:
 *
 * - **등록에서 도출한다** (R17). 자체 대상 목록을 두지 않는다 — 목록을 따로 두면
 *   등록 전수를 고정한 회귀 가드가 무의미해지고, 확장이 등록한 스케줄이 빠진다.
 * - **조건은 실행 시점에 평가한다** (R16). when()/skip() 은 소급 실행에서도
 *   그때 그 자리에서 판정한다 — 확장의 enabled_config 가 OFF 면 돌지 않는다.
 * - **중복 방지는 이벤트가 가진 mutex 를 그대로 쓴다** (R18). 서버 크론과 웹 크론이
 *   동시에 걸린 환경에서 두 진입점이 같은 자물쇠를 본다. 자물쇠는 **동시** 실행만 막는다 —
 *   서버 크론이 먼저 돌린 이벤트를 뒤이은 웹 호출의 소급 창이 다시 잡지 않는 것은, 서버 크론
 *   경로도 이벤트별 실행 시각을 같은 기록에 남기기 때문이다(`RecordServerCronEventRun`).
 *
 * 실행 창 소급 판정(D17)의 근거는 ScheduleRunState 가 보관한다.
 *
 * DB 스케줄 계통(schedules 테이블)은 등록기(`ScheduleRegistrar`)가 행마다 `schedule:{id}` 콜백
 * 이벤트로 등록하므로 이 러너도 등록된 이벤트로서 돌린다. 그 실행은 실행기(`ScheduleExecutor`)를
 * 거쳐 보안 검증(R15)과 행 기준 이력이 붙는다 — 이 러너는 그 이벤트에 자기 이력을 따로 남기지 않고,
 * 계기·실행자는 `ScheduleRunContext` 로 실행기에 넘긴다.
 * 큐 소진 경로는 확장 지점으로만 남긴다 (D22 — 이번 안에서 만들지 않는다).
 *
 * @since 7.1.0
 */
class ScheduleRunner
{
    /**
     * @var string 이 러너가 남기는 이력의 출처 값
     *
     * 화면 등록 예약(`database`)과 같은 표를 쓰되 이 값으로 갈린다 — 조회하는 쪽이
     * 리터럴을 다시 적으면 한쪽만 바뀌었을 때 목록이 조용히 빈다.
     */
    public const HISTORY_SOURCE = 'code';

    /** @var ScheduleRegistrar|null DB 스케줄 등록기 (처음 쓸 때 꺼낸다) */
    private ?ScheduleRegistrar $registrar = null;

    /**
     * @param  ScheduleRunState  $state  점검·실행 기록
     * @param  ArtisanCommandExtractor  $extractor  shell 이벤트 되뽑기
     * @param  CommandRunnerResolver  $runners  실행 모드 해석기
     * @param  ScheduleHistoryRepositoryInterface  $histories  실행 이력 저장소
     * @param  (callable(): float)|null  $clock  경과 시간 측정용 시계 (테스트 주입용, 기본: microtime)
     */
    public function __construct(
        private readonly ScheduleRunState $state,
        private readonly ArtisanCommandExtractor $extractor,
        private readonly CommandRunnerResolver $runners,
        private readonly ScheduleHistoryRepositoryInterface $histories,
        private $clock = null,
    ) {
        $this->clock ??= static fn (): float => microtime(true);
    }

    /**
     * 밀린 예약 작업을 실행합니다.
     *
     * 시각 축이 **둘**이다. 하나는 due 판정에 쓰는 **논리 시각**(`$now`)이고, 다른 하나는
     * 실행 상한을 재는 **경과 시간**이다. 둘을 같은 값으로 재면 논리 시각을 주입한 순간
     * 상한 판정이 실제 경과와 무관해진다 — 주입 시각이 과거면 첫 이벤트부터 "상한 초과"
     * 로 잘려 아무것도 실행되지 않고, 미래면 상한이 사실상 사라진다.
     *
     * @param  CarbonImmutable|null  $now  기준 시각 (테스트 주입용)
     * @param  ScheduleTriggerType  $trigger  트리거 유형 (예약 호출 / 화면의 [지금 실행])
     * @param  int|null  $userId  [지금 실행]을 누른 관리자 ID (예약 호출이면 null)
     * @return ScheduleRunSummary 실행 요약
     */
    public function run(
        ?CarbonImmutable $now = null,
        ScheduleTriggerType $trigger = ScheduleTriggerType::Scheduled,
        ?int $userId = null,
    ): ScheduleRunSummary {
        $startedAt = $now ?? CarbonImmutable::now();
        $startedElapsed = ($this->clock)();
        $budget = $this->maxRuntimeSeconds();

        /** @var Application $app */
        $app = app();

        $events = $this->registeredEvents($app);
        $windowStart = $this->windowStart($startedAt);

        $results = [];
        $executed = 0;
        $failed = 0;
        $skipped = 0;
        $due = 0;
        $truncated = false;

        foreach ($events as $key => $event) {
            $decision = $this->dueDecision($event, $key, $windowStart, $startedAt, $app);

            if ($decision['unparsable']) {
                // 주기를 해석하지 못한 이벤트를 **조용히 빼지 않는다.** 빼면 그 예약은
                // due 에도 skipped 에도 없어 요약 어디에도 나타나지 않고, 확장이 잘못된
                // 표현식을 등록한 사이트의 운영자는 그 작업이 안 돈다는 사실 자체를
                // 화면에서 볼 수 없다 — 되뽑기 실패를 드러내기로 한 것과 같은 종류의
                // 실패이므로 같은 자리에 싣는다.
                $due++;
                $failed++;
                $results[] = $this->result($event, 'failed', 'expression_unparsable');

                continue;
            }

            if (! $decision['due']) {
                continue;
            }

            $due++;

            // 실행 상한 — 남은 대상은 다음 호출로 넘긴다. 점검 시각을 전진시키지
            // 않으므로 다음 호출의 소급 창이 이 구간을 다시 덮는다.
            if ((($this->clock)() - $startedElapsed) >= $budget) {
                $truncated = true;
                break;
            }

            // 조건부 등록은 **실행 시점에** 평가한다 (R16).
            if (! $event->filtersPass($app)) {
                $skipped++;
                $results[] = $this->result($event, 'skipped', 'filtered');

                continue;
            }

            // 다중 서버 판정의 열쇠는 **due 시각**이다 — 호출 시각을 넘기면 서버마다
            // 부른 분(分)이 달라 같은 예약이 서로 다른 열쇠로 잠기고, 소급 창 덕에
            // 09:00 과 09:01 에 부른 두 서버가 같은 04:00 예약을 각각 실행한다 (C-4).
            if ($event->onOneServer && ! $this->serverShouldRun($event, $decision['previous_due'] ?? $startedAt)) {
                $skipped++;
                $results[] = $this->result($event, 'skipped', 'other_server');

                continue;
            }

            $eventStartedAt = $this->momentOf($startedAt, $startedElapsed);

            // DB 스케줄 이벤트(`schedule:{id}`)는 실행기가 행 기준 이력을 남긴다 — 여기서 또 남기면
            // 같은 실행이 내부 이름(`schedule:87`)을 단 기록과 짝을 이뤄 두 줄이 된다. 그 대신
            // 계기·실행자를 실행기까지 넘기고, 실행기가 마감한 결과를 요약에 그대로 센다.
            $managed = $this->registrar()->isManaged($event);

            $outcome = $managed
                ? $this->executeManaged($event, $app, $trigger, $userId)
                : $this->execute($event, $app);

            $eventEndedAt = $this->momentOf($startedAt, $startedElapsed);

            $results[] = $this->result($event, $outcome['status'], $outcome['reason'] ?? null);

            // 실행 이력은 **한 건씩** 남긴다 — 상태 파일의 '직전 1회' 요약은 다음 호출에
            // 덮여 사라지므로, 그것만으로는 「어젯밤 그 작업이 실패했나」를 나중에 볼 수
            // 없다. 건너뛴 이벤트는 남기지 않는다(실행되지 않았다는 사실은 요약이 나른다).
            if ($outcome['status'] !== 'skipped' && ! $managed) {
                $this->recordHistory($event, $key, $eventStartedAt, $eventEndedAt, $outcome, $trigger, $userId);
            }

            match ($outcome['status']) {
                'executed' => $executed++,
                'failed' => $failed++,
                default => $skipped++,
            };

            // 실행이 **끝난 뒤에만** 기록한다 — 실행 상한으로 중단된 잔여 이벤트가
            // 다음 호출에서 다시 잡히게 하기 위함이다.
            if ($outcome['status'] !== 'skipped') {
                $this->state->recordEventRun(
                    $key,
                    $this->momentOf($startedAt, $startedElapsed),
                    $outcome['status'] === 'failed',
                );
            }
        }

        $summary = new ScheduleRunSummary(
            startedAt: $startedAt,
            finishedAt: $this->momentOf($startedAt, $startedElapsed),
            due: $due,
            executed: $executed,
            failed: $failed,
            skipped: $skipped,
            truncated: $truncated,
            events: $results,
        );

        // 점검 시각은 due 전량을 처리했을 때만 전진한다.
        $this->state->recordRun($summary, advanceCheckpoint: ! $truncated);

        return $summary;
    }

    /**
     * 시작 시각에 지금까지의 경과를 더해 **논리 시각**을 만듭니다.
     *
     * 실행 기록과 요약의 종료 시각은 논리 시각 축 위에 있어야 한다 — 여기에 실제
     * 벽시계를 섞으면 시각을 주입한 호출에서 시작·종료가 서로 다른 축에 놓여
     * 소요 시간이 뜻 없는 값이 되고, 다음 호출의 소급 창 하한도 어긋난다.
     *
     * @param  CarbonImmutable  $startedAt  시작 논리 시각
     * @param  float  $startedElapsed  시작 시점의 경과 시계 값
     * @return CarbonImmutable 현재 논리 시각
     */
    private function momentOf(CarbonImmutable $startedAt, float $startedElapsed): CarbonImmutable
    {
        $elapsed = max(0.0, ($this->clock)() - $startedElapsed);

        return $startedAt->addMicroseconds((int) round($elapsed * 1_000_000));
    }

    /**
     * 등록된 스케줄 이벤트를 식별 키와 함께 반환합니다 (R17 — 등록에서 도출).
     *
     * @param  Application  $app  애플리케이션
     * @return array<string, Event> 키 → 이벤트
     */
    public function registeredEvents(Application $app): array
    {
        // 웹 요청에서는 콘솔 커맨드와 routes/console.php 가 아직 로드되지 않았다 —
        // 부팅해야 등록된 스케줄이 보인다. 이미 부팅된 경우 추가 비용은 없다.
        $app->make(ConsoleKernelContract::class)->bootstrap();

        $events = [];
        $seen = [];

        foreach ($app->make(Schedule::class)->events() as $index => $event) {
            $base = $this->eventKeyBase($event);

            // 같은 표현식·같은 대상이 두 번 등록될 수 있다 — 그때만 순번으로 가른다.
            $key = isset($seen[$base]) ? $base.'#'.$index : $base;
            $seen[$base] = true;

            $events[$key] = $event;
        }

        return $events;
    }

    /**
     * 등록된 이벤트 객체의 식별 키를 찾습니다 (서버 크론 경로의 실행 기록용).
     *
     * 키는 `registeredEvents()` 가 매기는 것과 같아야 한다 — 같은 표현식·대상이 두 번 등록되면
     * 순번이 붙으므로, 키를 다시 계산하지 않고 등록 목록에서 **같은 객체**를 찾는다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  Application  $app  애플리케이션
     * @return string|null 식별 키 (이 스케줄에 등록된 이벤트가 아니면 null)
     */
    public function eventKeyFor(Event $event, Application $app): ?string
    {
        foreach ($this->registeredEvents($app) as $key => $candidate) {
            if ($candidate === $event) {
                return $key;
            }
        }

        return null;
    }

    /**
     * 한 번의 호출에 허용되는 실행 시간(초).
     *
     * PHP 실행 제한(`max_execution_time`)이 설정값보다 짧으면 그 8할로 자른다 — 상한에
     * 닿기 전에 PHP 가 요청을 죽이면 요약도 이월 기록도 남지 않는다. 8할은 형제
     * `SubprocessCommandRunner::readTimeoutSeconds()` 와 같은 여유다. 제한이 없으면(0 —
     * CLI 기본값) 설정값 그대로다.
     *
     * @return int 초
     */
    public function maxRuntimeSeconds(): int
    {
        $configured = max(1, (int) config('core.cron.max_runtime_seconds', 50));

        // `ini_get` 도 차단 대상이다 — 못 읽으면 「무제한」과 같이 다룬다(형제 러너와 같은 전제).
        $limit = ExecutionEnvironment::hasFunction('ini_get') ? (int) ini_get('max_execution_time') : 0;

        if ($limit <= 0) {
            return $configured;
        }

        return max(1, min($configured, (int) floor($limit * 0.8)));
    }

    /**
     * 소급 판정의 상한 구간(분).
     *
     * @return int 분
     */
    public function catchUpWindowMinutes(): int
    {
        return max(1, (int) config('core.cron.catch_up_window_minutes', 1440));
    }

    /**
     * 이벤트의 식별 키 기반 문자열을 만듭니다.
     *
     * 프레임워크의 mutex 이름을 그대로 쓰지 않는다 — CallbackEvent 의 mutex 이름은
     * 설명(description)이 없으면 전부 같은 값이 되어 서로 다른 이벤트가 한 칸을
     * 공유한다. 여기서는 실행 주기와 대상을 함께 넣어 그 충돌을 좁힌다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @return string 키 기반 문자열
     */
    private function eventKeyBase(Event $event): string
    {
        $target = $event instanceof CallbackEvent
            ? (string) ($event->description ?? $event->getSummaryForDisplay())
            : Event::normalizeCommand((string) ($event->command ?? ''));

        return sha1($event->expression.'|'.$target);
    }

    /**
     * 소급 창의 하한을 계산합니다 (D17).
     *
     * @param  CarbonImmutable  $now  기준 시각
     * @return CarbonImmutable 창 하한
     */
    private function windowStart(CarbonImmutable $now): CarbonImmutable
    {
        $cap = $now->subMinutes($this->catchUpWindowMinutes());
        $lastChecked = $this->state->lastCheckedAt();

        if ($lastChecked === null) {
            return $cap;
        }

        // 장기 미호출 뒤 첫 호출이 폭주하지 않도록 상한 구간으로 자른다.
        return $lastChecked->lessThan($cap) ? $cap : $lastChecked;
    }

    /**
     * 이벤트가 소급 창 안에서 실행 대상인지 판정합니다.
     *
     * 창 안에서 여러 번 due 였던 주기 이벤트도 **1회만** 대상이 된다 — 같은 진입점이 놓친
     * 회차를 횟수대로 보전하지 않는다는 뜻이고, 반복은 실행 상한만 소모한다. 이미 실행된
     * 회차는 진입점과 무관하게 다시 돌지 않는다 — 서버 크론(`schedule:run`)이 돌린 이벤트도
     * 같은 실행 기록(`lastRunAt`)에 남아 이 판정의 하한이 된다.
     *
     * 판정 결과가 **세 갈래**인 것이 중요하다: 대상이다 / 대상이 아니다 / **주기를
     * 해석할 수 없다**. 셋째를 둘째에 합치면 잘못된 표현식을 등록한 예약이 요약에서
     * 통째로 사라져, 「안 돌고 있다」는 사실이 어디에도 남지 않는다.
     *
     * 그리고 `previous_due` 를 함께 돌려준다 — 다중 서버 잠금의 열쇠가 호출 시각이
     * 아니라 이 값이어야 하기 때문이다 (C-4).
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  string  $key  이벤트 식별 키
     * @param  CarbonImmutable  $windowStart  창 하한
     * @param  CarbonImmutable  $now  기준 시각
     * @param  Application  $app  애플리케이션
     * @return array{due: bool, unparsable: bool, previous_due: CarbonImmutable|null} 판정 결과
     */
    private function dueDecision(
        Event $event,
        string $key,
        CarbonImmutable $windowStart,
        CarbonImmutable $now,
        Application $app,
    ): array {
        $miss = ['due' => false, 'unparsable' => false, 'previous_due' => null];

        // 유지보수 모드·환경 제한은 프레임워크의 판정(Event::isDue)과 같은 기준을 쓴다.
        // 이 둘은 「지금 이 사이트에서는 대상이 아니다」이지 고장이 아니므로 조용히 뺀다.
        if (! $event->runsInMaintenanceMode() && $app->isDownForMaintenance()) {
            return $miss;
        }

        if (! $event->runsInEnvironment($app->environment())) {
            return $miss;
        }

        $lastRun = $this->state->lastRunAt($key);
        $lowerBound = ($lastRun !== null && $lastRun->greaterThan($windowStart))
            ? $lastRun
            : $windowStart;

        $previousDue = $this->previousDueTime($event, $now);

        if ($previousDue === null) {
            return ['due' => false, 'unparsable' => true, 'previous_due' => null];
        }

        return [
            'due' => $previousDue->greaterThan($lowerBound),
            'unparsable' => false,
            'previous_due' => $previousDue,
        ];
    }

    /**
     * 기준 시각 이하의 가장 최근 due 시각을 계산합니다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  CarbonImmutable  $now  기준 시각
     * @return CarbonImmutable|null 계산 실패 시 null
     */
    private function previousDueTime(Event $event, CarbonImmutable $now): ?CarbonImmutable
    {
        try {
            $timezone = $event->timezone !== null ? (string) $event->timezone : null;
            $reference = $timezone !== null ? $now->setTimezone($timezone) : $now;

            $previous = (new CronExpression($event->expression))->getPreviousRunDate(
                $reference->format('Y-m-d H:i:s'),
                0,
                true,
                $timezone,
            );

            return CarbonImmutable::instance($previous)->setTimezone($now->getTimezone());
        } catch (\Throwable $e) {
            Log::warning('스케줄 실행 주기를 해석하지 못했습니다.', [
                'expression' => $event->expression,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 이벤트를 실행합니다 (계통별 분기).
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  Container  $container  컨테이너
     * @return array{status: string, reason?: string} 실행 결과
     */
    private function execute(Event $event, Container $container): array
    {
        if ($event instanceof CallbackEvent) {
            return $this->executeCallback($event, $container);
        }

        return $this->executeArtisan($event, $container);
    }

    /**
     * DB 스케줄 등록기 (관리 이벤트 판정용).
     *
     * 생성자로 받지 않는다 — 시계를 다섯째 인자로 넘기는 호출부(시험)가 있어 인자 순서를 바꿀 수 없고,
     * 판정은 이름 접두사만 보므로 호출마다 꺼내도 비용이 없다.
     *
     * @return ScheduleRegistrar 등록기
     */
    private function registrar(): ScheduleRegistrar
    {
        return $this->registrar ??= app(ScheduleRegistrar::class);
    }

    /**
     * DB 스케줄 이벤트를 실행하고 실행기가 마감한 결과로 판정합니다.
     *
     * 등록기의 콜백은 실행기의 실패를 예외로 올리지 않는다(실패도 이력으로 마감하고 돌아온다). 그래서
     * 콜백이 예외 없이 끝났다는 것만으로는 성공이 아니다 — 실행 문맥에 돌려받은 이력·거부를 본다.
     *
     * - 실패 이력 → 실패 (사유는 이력의 오류 출력)
     * - 수동 실행 중복 거부(앞선 실행이 돌고 있음) → 건너뜀 `overlapping` — 실행 기록을 남기지 않아 다음 호출이 다시 잡는다
     * - 그 밖의 실행 전 거부 → 실패 (사유는 거부 메시지)
     * - 성공 이력·큐 투입·「실패만 기록」 성공·하트비트 → 실행됨
     *
     * @param  Event  $event  `schedule:` 이벤트
     * @param  Container  $container  컨테이너
     * @param  ScheduleTriggerType  $trigger  실행 계기
     * @param  int|null  $userId  수동 실행자 ID
     * @return array{status: string, reason?: string} 실행 결과
     */
    private function executeManaged(Event $event, Container $container, ScheduleTriggerType $trigger, ?int $userId): array
    {
        return ScheduleRunContext::within($trigger, $userId, function () use ($event, $container): array {
            $outcome = $this->execute($event, $container);

            if ($outcome['status'] !== 'executed') {
                return $outcome;
            }

            $reported = ScheduleRunContext::outcome();

            if ($reported instanceof ScheduleExecutionException && $reported->reasonCode === 'already_running') {
                return ['status' => 'skipped', 'reason' => 'overlapping'];
            }

            if ($reported instanceof \Throwable) {
                return ['status' => 'failed', 'reason' => $reported->getMessage()];
            }

            if ($reported instanceof ScheduleHistory && $reported->status === ScheduleResultStatus::Failed) {
                return ['status' => 'failed', 'reason' => mb_substr((string) ($reported->error_output ?? 'failed'), 0, 500)];
            }

            return $outcome;
        });
    }

    /**
     * Schedule::call() / Schedule::job() 이벤트를 실행합니다.
     *
     * 이미 in-process 이므로 프레임워크의 실행 경로를 그대로 쓴다 — before/after 콜백이
     * 그 안에 있다.
     *
     * **중복 방지 자물쇠는 이 메서드가 소유한다.** `shouldSkipDueToOverlapping()` 은
     * 판정이 아니라 자물쇠를 **생성**하는 호출이고, 프레임워크의 `Event::run()` 첫 줄이
     * 같은 호출을 다시 한다. 그대로 부르면 두 번째 생성이 실패해 `run()` 이 **아무것도
     * 실행하지 않고 조용히 반환**하는데, 예외가 없으므로 러너는 그것을 '실행됨' 으로
     * 집계한다 — 사이트맵이 한 번도 갱신되지 않는데 화면은 매번 「1건 처리」를 보여
     * 주는 상태가 된다. 게다가 `finish()` 를 거치지 않아 자물쇠가 남아, 이후 호출은
     * 만료까지 전부 'overlapping' 으로 건너뛴다.
     *
     * 그래서 자물쇠를 잡은 뒤에는 프레임워크의 재검사를 **끄고** 부르고, 해제도 이
     * 메서드가 책임진다. 아래 shell 경로가 `finish()` 를 직접 부르는 것과 같은 구조다.
     *
     * @param  CallbackEvent  $event  콜백 이벤트
     * @param  Container  $container  컨테이너
     * @return array{status: string, reason?: string} 실행 결과
     */
    private function executeCallback(CallbackEvent $event, Container $container): array
    {
        if ($event->shouldSkipDueToOverlapping()) {
            return ['status' => 'skipped', 'reason' => 'overlapping'];
        }

        // 자물쇠는 위에서 이미 잡혔다 — 재검사를 끄지 않으면 run() 이 그 자물쇠에 걸린다.
        $heldLock = $event->withoutOverlapping;
        $event->withoutOverlapping = false;

        try {
            $event->run($container);

            return ['status' => 'executed'];
        } catch (\Throwable $e) {
            Log::error('예약 작업 실행 실패', [
                'event' => $event->getSummaryForDisplay(),
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reason' => $e->getMessage()];
        } finally {
            $event->withoutOverlapping = $heldLock;

            // 재검사를 꺼 둔 동안에는 프레임워크의 removeMutex 가 no-op 이므로 여기서 푼다.
            if ($heldLock) {
                $event->mutex->forget($event);
            }
        }
    }

    /**
     * shell 형태 이벤트를 되뽑아 같은 프로세스에서 실행합니다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  Container  $container  컨테이너
     * @return array{status: string, reason?: string} 실행 결과
     */
    private function executeArtisan(Event $event, Container $container): array
    {
        try {
            $extraction = $this->extractor->extract($event);
        } catch (ScheduleCommandExtractionException $e) {
            // 조용히 건너뛰지 않는다 — 건너뛰면 그 예약이 오류 없이 사라진다.
            Log::error('예약 작업의 커맨드를 되뽑지 못했습니다.', [
                'command' => $e->rawCommand,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }

        // shouldSkipDueToOverlapping 은 mutex 를 **생성**한다 — 여기서부터 finish()
        // 까지가 임계 구역이고, 예외로 빠져나가도 finally 의 finish 가 자물쇠를 푼다.
        if ($event->shouldSkipDueToOverlapping()) {
            return ['status' => 'skipped', 'reason' => 'overlapping'];
        }

        $exitCode = 1;

        try {
            $event->callBeforeCallbacks($container);

            // 웹 크론의 존재 이유가 "CLI 스폰 불가" 이므로 이 경로는 모드 판정과
            // 무관하게 언제나 in-process 다 (해석기에 모드를 명시한다).
            $result = $this->runners->resolve(ExecutionMode::Inprocess)
                ->run($extraction->command, $extraction->parameters);

            $exitCode = $result->exitCode;

            if ($exitCode !== 0) {
                return ['status' => 'failed', 'reason' => 'exit_code:'.$exitCode];
            }

            return ['status' => 'executed'];
        } catch (\Throwable $e) {
            Log::error('예약 작업 실행 실패', [
                'command' => $extraction->display(),
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reason' => $e->getMessage()];
        } finally {
            $event->finish($container, $exitCode);
        }
    }

    /**
     * 다중 서버 환경에서 이 서버가 실행 주체인지 판정합니다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  CarbonImmutable  $now  기준 시각
     * @return bool 실행해야 하면 true
     */
    private function serverShouldRun(Event $event, CarbonImmutable $now): bool
    {
        try {
            return app(Schedule::class)->serverShouldRun($event, $now->toDateTime());
        } catch (\Throwable) {
            // 판정 수단(캐시)이 없으면 단일 서버로 본다 — 실행을 막지 않는다.
            return true;
        }
    }

    /**
     * 실행 이력 한 건을 남깁니다.
     *
     * 코드로 등록된 예약은 `schedules` 행이 없으므로 `schedule_id` 없이 `source=code`
     * 로 적고, 어느 예약인지는 실행 시점 스냅샷(키·표시명·주기)으로 남긴다. 표를 화면
     * 등록 예약과 **공유**하는 이유는, 예약 관리 화면이 정식 공개될 때 이력이 두 군데로
     * 갈리지 않게 하기 위해서다.
     *
     * 이력 쓰기 실패가 실행을 되돌리지는 않는다 — 작업은 이미 수행됐고, 그 사실을
     * 기록하지 못한 것과 수행하지 못한 것은 다르다. 대신 오류로 남긴다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  string  $key  이벤트 식별 키
     * @param  CarbonImmutable  $startedAt  실행 시작 시각
     * @param  CarbonImmutable  $endedAt  실행 종료 시각
     * @param  array{status: string, reason?: string}  $outcome  실행 결과
     * @param  ScheduleTriggerType  $trigger  트리거 유형
     * @param  int|null  $userId  수동 실행자 ID
     * @return void 반환값 없음
     */
    private function recordHistory(
        Event $event,
        string $key,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
        array $outcome,
        ScheduleTriggerType $trigger,
        ?int $userId = null,
    ): void {
        $failed = $outcome['status'] === 'failed';

        try {
            $this->histories->create([
                'source' => self::HISTORY_SOURCE,
                'schedule_id' => null,
                'event_key' => $key,
                'event_name' => $this->displayNameOf($event),
                'event_expression' => $event->expression,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration' => max(0, $endedAt->diffInSeconds($startedAt, absolute: true)),
                'status' => $failed ? ScheduleResultStatus::Failed : ScheduleResultStatus::Success,
                'exit_code' => $failed ? 1 : 0,
                'error_output' => $failed ? ($outcome['reason'] ?? null) : null,
                'trigger_type' => $trigger,
                'triggered_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            Log::error('예약 작업 실행 이력을 남기지 못했습니다.', [
                'event' => $this->displayNameOf($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 이력 화면에 쓸 예약 작업 이름을 만듭니다.
     *
     * `Event::getSummaryForDisplay()` 는 설명이 없으면 **실행할 명령 원문**을 돌려주는데,
     * 그 원문에는 이 서버의 PHP 실행 파일 절대 경로가 들어간다
     * (`"C:\laragon\bin\php\...\php.EXE" "artisan" seo:prune-stats`). 운영자 화면에
     * 그대로 나가면 읽을 수 없을 뿐 아니라 서버 경로가 노출된다 — 확장 예약은 설명을 달아
     * 두어 사람이 읽는 이름이 나오는데 코어 예약은 대부분 설명이 없어 이 갈래로 떨어졌다
     * (G8 실측 2026-09-02 · 계획서 §9.11.3 F6).
     *
     * 우선순위는 ① 사람이 붙인 설명 ② 되뽑은 Artisan 커맨드 ③ 원문(되뽑기 불가) 이다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @return string 화면에 쓸 이름
     */
    private function displayNameOf(Event $event): string
    {
        if (is_string($event->description) && $event->description !== '') {
            return $event->description;
        }

        try {
            return $this->extractor->extract($event)->display();
        } catch (\Throwable) {
            // 되뽑을 수 없는 이벤트(클로저·쉘 명령)는 원문이 그대로 최선의 단서다.
            return $event->getSummaryForDisplay();
        }
    }

    /**
     * 이벤트별 결과 한 줄을 만듭니다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @param  string  $status  executed|failed|skipped
     * @param  string|null  $reason  사유
     * @return array<string, mixed> 결과 배열
     */
    private function result(Event $event, string $status, ?string $reason): array
    {
        return array_filter([
            'name' => $event->getSummaryForDisplay(),
            'expression' => $event->expression,
            'status' => $status,
            'reason' => $reason,
        ], static fn ($value) => $value !== null);
    }
}
