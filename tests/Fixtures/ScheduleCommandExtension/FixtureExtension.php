<?php

namespace Tests\Fixtures\ScheduleCommandExtension;

/**
 * 예약 커맨드 해석 테스트용 가짜 확장.
 *
 * 이 클래스 파일이 놓인 디렉토리가 확장 루트로 해석되고, 그 아래 `src/` 가 조회 범위가 된다
 * (`ScheduleCommandCacheManager::extensionSourcePath()` — 실제 확장과 같은 규약).
 */
class FixtureExtension
{
    /**
     * @param  array<int, array<string, mixed>>  $schedules  선언할 예약 목록
     */
    public function __construct(
        private readonly array $schedules,
        private readonly string $identifier = 'fixture-schedule_ext',
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSchedules(): array
    {
        return $this->schedules;
    }
}
