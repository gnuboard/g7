<?php

namespace App\Services;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Enums\ScheduleTriggerType;
use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Helpers\TimezoneHelper;
use App\Models\ScheduleHistory;
use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\WebCronPolicy;
use App\Scheduling\WebCronTokenStore;
use App\Services\Schedule\ScheduleRegistrar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * 실행 방식·예약 작업(웹 크론) 화면의 서비스 계층 (W5).
 *
 * 화면이 필요로 하는 판정을 한곳에 모은다 — 실행 모드의 근거, 예약 작업이 마지막으로
 * 돈 시각, 호출 주소. 컨트롤러와 대시보드 경고 리스너가 같은 판정을 각자 만들면
 * 두 화면이 같은 사이트를 두고 다른 말을 한다.
 *
 * @since 7.1.0
 */
class SystemExecutionService
{
    /** @var int 화면이 보여 주는 최근 실행 이력 건수 */
    private const RECENT_HISTORY_LIMIT = 20;

    /** @var int 실행 이력 한 줄에 싣는 오류 출력 길이 */
    private const ERROR_PREVIEW_LENGTH = 500;

    /**
     * @param  CommandRunnerResolver  $resolver  실행 모드 해석기
     * @param  ScheduleRunner  $runner  스케줄 러너 본체
     * @param  ScheduleRunState  $state  점검·실행 기록
     * @param  WebCronPolicy  $policy  웹 크론 호출 정책
     * @param  WebCronTokenStore  $tokens  호출 번호 보관처
     * @param  ScheduleHistoryRepositoryInterface  $histories  실행 이력 저장소
     */
    public function __construct(
        private readonly CommandRunnerResolver $resolver,
        private readonly ScheduleRunner $runner,
        private readonly ScheduleRunState $state,
        private readonly WebCronPolicy $policy,
        private readonly WebCronTokenStore $tokens,
        private readonly ScheduleHistoryRepositoryInterface $histories,
    ) {}

    /**
     * 화면 상단 카드와 예약 작업 영역이 쓰는 상태를 반환합니다.
     *
     * 호출 주소(토큰)는 **싣지 않는다** (D20) — 화면은 [보기]·[복사] 시점에 전용
     * 경로로 받아 온다. 대신 "발급되어 있는가" 만 알린다.
     *
     * @return array<string, mixed> 상태 배열
     */
    public function status(): array
    {
        $detection = $this->detectionReport();

        return [
            'execution' => [
                'effective_mode' => $this->resolver->resolveMode()->value,
                'configured_mode' => $this->configuredMode()->value,
                'detection' => $detection,
            ],
            'cron' => [
                'mode' => $this->policy->mode(),
                'min_interval_minutes' => $this->policy->minIntervalMinutes(),
                'visit_fallback_enabled' => $this->policy->visitFallbackEnabled(),
                'token_issued' => $this->tokens->exists(),
                'token_issued_at' => $this->toSiteTime($this->tokens->issuedAt()),
                'last_run' => $this->localizeSummaryTimes($this->state->lastRunSummary()),
                'last_executed_at' => $this->toSiteTime($this->state->lastExecutedAt()),
                'stale' => $this->isStale(),
                'stale_after_days' => $this->staleAfterDays(),
                // 직전 1회 요약(`last_run`)은 다음 호출에 덮인다 — 지난 실행을 되짚으려면
                // 누적 이력이 따로 있어야 한다.
                'recent_history' => $this->recentHistory(),
                // 상태 파일을 쓰지 못하면 점검 시각이 남지 않아 다음 호출의 소급 창이
                // 상한까지 열리고 창 안의 예약이 **매번 전부 다시** 실행된다. 오류가 아니라
                // 부하로만 나타나므로 화면이 그 사유를 알려야 한다.
                'state_persist_failed' => $this->state->persistFailed(),
            ],
        ];
    }

    /**
     * 실행 환경 감지 결과(판정 근거)를 반환합니다.
     *
     * 판정 사유는 코어가 번역 키(`message_key`)와 치환값으로 실어 보낸다 — 원문
     * (`message`)은 코어가 만든 진단 문자열이라 로케일을 따르지 않는다. 화면은 키를
     * 조합해 번역할 수단이 없으므로(동적 키 + 치환) 여기서 번역해 `message_localized`
     * 로 함께 내려보낸다. 원문은 그대로 둔다 — 디버그 화면이 그것을 쓴다.
     *
     * @return array<string, mixed> 감지 결과
     */
    public function detectionReport(): array
    {
        $report = $this->resolver->detectionReport();

        $key = (string) ($report['php_probe']['message_key'] ?? '');

        if ($key !== '') {
            $params = $report['php_probe']['message_params'] ?? [];

            $report['php_probe']['message_localized'] = __(
                'settings.'.$key,
                is_array($params) ? $params : [],
            );
        }

        return $report;
    }

    /**
     * 운영자가 고정한 실행 모드를 반환합니다 (미고정이면 Auto).
     *
     * 해석은 모드 판정과 같은 원천(`CommandRunnerResolver::configuredMode`)에 맡긴다.
     *
     * @return ExecutionMode 저장된 모드
     */
    public function configuredMode(): ExecutionMode
    {
        return $this->resolver->configuredMode();
    }

    /**
     * 호출 주소를 발급 상태 그대로 반환합니다 (없으면 그 자리에서 발급).
     *
     * @return array<string, mixed> 호출 주소와 발급 시각
     */
    public function revealCallUrl(): array
    {
        $token = $this->tokens->ensure();
        $this->tokens->refresh();

        return [
            'url' => $this->tokens->callUrl($token),
            'header_name' => WebCronTokenStore::TOKEN_HEADER,
            'issued_at' => $this->toSiteTime($this->tokens->issuedAt()),
        ];
    }

    /**
     * 호출 번호를 재발급하고 새 주소를 반환합니다.
     *
     * @return array<string, mixed> 새 호출 주소와 발급 시각
     */
    public function rotateCallUrl(): array
    {
        $token = $this->tokens->rotate();

        return [
            'url' => $this->tokens->callUrl($token),
            'header_name' => WebCronTokenStore::TOKEN_HEADER,
            'issued_at' => $this->toSiteTime($this->tokens->issuedAt()),
        ];
    }

    /**
     * 예약 작업을 지금 한 번 실행합니다.
     *
     * 응답 시각은 상태 조회(`status()`)의 `last_run` 과 같이 사이트 시간대로 옮긴다 — 화면은 이 응답을
     * 「마지막 실행」 줄에 바로 그리므로, 표준시로 내보내면 누른 직후에만 9시간 어긋난 시각이 보이고
     * 새로고침하면 바뀐다.
     *
     * @param  int|null  $userId  누른 관리자 ID (실행 이력의 실행자로 남는다)
     * @return array<string, mixed> 실행 요약
     */
    public function runNow(?int $userId = null): array
    {
        $now = CarbonImmutable::now();

        $this->state->recordInvocation($now);

        // 화면에서 누른 실행은 예약 호출과 이력에서 구분된다 — 「왜 이 시각에 돌았나」를
        // 되짚을 때 사람이 누른 것과 주기가 도래한 것은 다른 사실이다.
        return $this->localizeSummaryTimes(
            $this->runner->run($now, ScheduleTriggerType::Manual, $userId)->toArray(),
        ) ?? [];
    }

    /**
     * 시각을 사이트 시간대 문자열로 옮깁니다.
     *
     * `TimezoneHelper::toUserTimezone()` 은 `Carbon\Carbon` 만 받는데 이 서비스가 다루는
     * 값에는 `CarbonImmutable`(상태 파일·토큰 저장소)이 섞여 있다 — 불변형은 그 클래스의
     * 하위형이 아니라 그대로 넘기면 형 오류로 500 이 된다. 흡수를 호출부마다 하지 않고
     * 여기 한 곳에 둔다.
     *
     * @param  CarbonInterface|null  $at  변환할 시각
     * @return string|null 사이트 시간대 ISO8601 문자열
     */
    private function toSiteTime(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return TimezoneHelper::toUserTimezone(Carbon::instance($at));
    }

    /**
     * 실행 요약의 시각을 사이트 시간대로 옮깁니다.
     *
     * 상태 파일에는 UTC ISO 문자열로 저장한다(기계가 비교하는 값이라 그래야 한다). 화면에
     * 그대로 내보내면 같은 화면의 형제 목록(`ScheduleResource`)은 사이트 시간대인데 이쪽만
     * 표준시라, 운영자가 9시간 차이를 「안 돌았다」로 읽는다
     * (G8 실측 2026-09-02 · 계획서 §9.11.3 F5).
     *
     * @param  array<string, mixed>|null  $summary  실행 요약
     * @return array<string, mixed>|null 시각을 옮긴 요약
     */
    private function localizeSummaryTimes(?array $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        foreach (['started_at', 'finished_at'] as $field) {
            $value = $summary[$field] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $summary[$field] = $this->toSiteTime(Carbon::parse($value));
            } catch (\Throwable) {
                // 해석할 수 없는 값은 그대로 둔다 — 지우면 화면에서 그 사실이 사라진다.
            }
        }

        return $summary;
    }

    /**
     * 예약 작업의 최근 실행 이력을 화면용 배열로 반환합니다.
     *
     * 7.2.0 부터 예약 작업 대부분이 `schedules` 행으로 돌아 실행기가 행 기준 이력(`source=database`)을
     * 남긴다 — 코드 예약 이력만 실으면 이 목록이 거의 빈다. 그래서 두 출처를 시각순으로 함께 싣고,
     * 행 이력의 이름은 스케줄 관리 화면과 같은 표시 명칭(지금 언어 → 기본 언어 → 식별자)으로 푼다.
     *
     * 예전 판 웹 러너가 DB 스케줄 이벤트에 남긴 코드 이력(이름이 `schedule:{id}`)은 뺀다 — 같은 실행의
     * 행 이력이 이미 있고, 이름이 내부 식별자라 운영자가 알아볼 수 없다.
     *
     * @return array<int, array<string, mixed>> 시각 역순 이력
     */
    private function recentHistory(): array
    {
        return $this->histories
            ->getRecentRuns(self::RECENT_HISTORY_LIMIT, ScheduleRegistrar::EVENT_PREFIX)
            ->map(fn ($history): array => [
                'id' => $history->id,
                'name' => $this->historyName($history),
                'expression' => $history->event_expression ?? $history->schedule?->expression,
                'status' => $history->status?->value,
                'trigger_type' => $history->trigger_type?->value,
                'started_at' => $this->toSiteTime($history->started_at),
                'duration' => $history->duration,
                // 행 이력의 오류 출력은 최대 65,535자다 — 화면은 한 줄만 보이므로 앞부분만 싣는다.
                'error_output' => $history->error_output !== null
                    ? mb_substr((string) $history->error_output, 0, self::ERROR_PREVIEW_LENGTH)
                    : null,
            ])
            ->all();
    }

    /**
     * 실행 이력 한 건의 화면 이름을 만듭니다.
     *
     * @param  ScheduleHistory  $history  실행 이력
     * @return string 표시 이름
     */
    private function historyName(ScheduleHistory $history): string
    {
        if ($history->schedule_id !== null) {
            $schedule = $history->schedule;

            if ($schedule !== null) {
                return $schedule->localizedLabel();
            }
        }

        if (is_string($history->event_name) && $history->event_name !== '') {
            return $history->event_name;
        }

        return $history->schedule_id !== null ? '#'.$history->schedule_id : '';
    }

    /**
     * 예약 작업이 기준 일수 이상 실행되지 않았는지 판정합니다 (D19).
     *
     * 판정은 **실행 모드와 무관**하다 (D30) — 서버 크론이 고장 난 단독 서버도 같은
     * 기준으로 발화한다. 갈리는 것은 조치 안내 문구뿐이다.
     *
     * 실행 기록이 아예 없는 사이트는 경고하지 않는다. 설치 직후부터 경고가 떠 있으면
     * 그 경고가 배경이 되어, 정말로 멈춘 사이트의 신호와 구분되지 않는다.
     *
     * @return bool 경고 대상 여부
     */
    public function isStale(): bool
    {
        $lastExecuted = $this->state->lastExecutedAt();

        if ($lastExecuted === null) {
            return false;
        }

        return $lastExecuted->addDays($this->staleAfterDays())->isPast();
    }

    /**
     * 미실행 경고의 발화 기준(일).
     *
     * @return int 일
     */
    public function staleAfterDays(): int
    {
        return max(1, (int) config('core.cron.stale_alert_days', 2));
    }

    /**
     * 예약 작업이 마지막으로 실행된 시각.
     *
     * @return CarbonImmutable|null 기록이 없으면 null
     */
    public function lastExecutedAt(): ?CarbonImmutable
    {
        return $this->state->lastExecutedAt();
    }
}
