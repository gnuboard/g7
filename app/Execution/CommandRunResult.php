<?php

namespace App\Execution;

/**
 * 커맨드 실행 결과 (Value Object).
 *
 * 두 러너(서브프로세스·in-process)가 같은 형태로 결과를 돌려주기 위한 불변 DTO.
 * 등가 계약 테스트가 이 형태를 기준으로 두 러너를 대조한다.
 */
final class CommandRunResult
{
    /**
     * @param  int  $exitCode  종료 코드 (0 = 성공)
     * @param  string  $output  표준 출력 + 표준 오류 (병합)
     * @param  ExecutionMode  $mode  실행에 사용된 모드 (Subprocess|Inprocess)
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly ExecutionMode $mode,
    ) {}

    /**
     * 실행이 성공했는지 여부.
     *
     * @return bool 종료 코드 0 여부
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
