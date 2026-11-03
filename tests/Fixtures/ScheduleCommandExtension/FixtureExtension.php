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
     * 테스트가 넘긴 예약 목록과 식별자를 그대로 보관하는 가짜 확장을 만든다.
     *
     * @param  array<int, array<string, mixed>>  $schedules  선언할 예약 목록
     * @param  string  $identifier  확장 식별자 (기본값은 픽스처 전용 식별자)
     */
    public function __construct(
        private readonly array $schedules,
        private readonly string $identifier = 'fixture-schedule_ext',
    ) {}

    /**
     * 생성 시 받은 확장 식별자를 돌려준다.
     *
     * @return string 확장 식별자
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * 생성 시 받은 예약 목록을 실제 확장의 `getSchedules()` 처럼 그대로 돌려준다.
     *
     * @return array<int, array<string, mixed>> 선언된 예약 목록
     */
    public function getSchedules(): array
    {
        return $this->schedules;
    }
}
