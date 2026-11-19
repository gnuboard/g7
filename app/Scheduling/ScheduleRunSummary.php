<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * 스케줄 러너 한 번의 호출 결과 요약.
 *
 * 웹 크론 응답 본문과 관리자 화면의 "마지막 실행" 표시, 그리고 상태 파일에 남는
 * 기록이 모두 이 형태를 쓴다 — 세 곳이 각자 요약을 만들면 같은 실행을 두고 다른
 * 수치를 말하게 된다.
 *
 * @since 7.1.0
 */
final class ScheduleRunSummary
{
    /**
     * @param  CarbonImmutable  $startedAt  실행 시작 시각
     * @param  CarbonImmutable  $finishedAt  실행 종료 시각
     * @param  int  $due  소급 창 안에서 실행 대상으로 뽑힌 이벤트 수
     * @param  int  $executed  실제로 실행한 이벤트 수
     * @param  int  $failed  실패한 이벤트 수 (되뽑기 실패 포함)
     * @param  int  $skipped  건너뛴 이벤트 수 (조건 미충족·중복 실행 방지)
     * @param  bool  $truncated  실행 상한에 걸려 남은 대상을 다음 호출로 넘겼는지
     * @param  list<array<string, mixed>>  $events  이벤트별 결과
     */
    public function __construct(
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $finishedAt,
        public readonly int $due,
        public readonly int $executed,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly bool $truncated,
        public readonly array $events = [],
    ) {}

    /**
     * 소요 시간(밀리초).
     *
     * @return int 밀리초
     */
    public function durationMs(): int
    {
        return (int) round(($this->finishedAt->getTimestampMs() - $this->startedAt->getTimestampMs()));
    }

    /**
     * 응답·상태 기록용 배열 표현.
     *
     * @return array<string, mixed> 요약 배열
     */
    public function toArray(): array
    {
        return [
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'duration_ms' => $this->durationMs(),
            'due' => $this->due,
            'executed' => $this->executed,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'truncated' => $this->truncated,
            'events' => $this->events,
        ];
    }
}
