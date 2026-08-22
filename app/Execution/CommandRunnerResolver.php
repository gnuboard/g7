<?php

namespace App\Execution;

use App\Execution\Contracts\CommandRunner;
use App\Support\ExecutionEnvironment;

/**
 * 실행 모드 해석기 (판정 축).
 *
 * VendorResolver 와 같은 골격 — "무엇을 쓸지"(모드 판정) 와 "어떻게 실행할지"(러너)
 * 를 분리해, 모드를 읽는 곳과 실행하는 곳이 각자 판정을 갖는 것을 막는다.
 *
 * 판정 우선순위:
 * 1. 호출부 명시 모드 (subprocess/inprocess)
 * 2. 운영자 저장 설정 `system.execution_mode` (auto 가 아닐 때)
 * 3. 환경 감지 (ExecutionEnvironment::detect — 매 판정마다 감지한다.
 *    설치 시점 감지 결과를 값으로 박지 않으므로 호스팅 환경 변화를 따라간다)
 *
 * fail-safe: 판정이 불확실하면 subprocess(현행 경로) — 기본값 불변 테스트가 고정한다.
 */
class CommandRunnerResolver
{
    /**
     * @param  callable|null  $detector  환경 감지 콜백 (테스트 주입용, 기본: ExecutionEnvironment::detect)
     *                                   시그니처: fn (string $phpBinary): array{mode: string, ...}
     */
    public function __construct(
        private $detector = null,
    ) {}

    /**
     * 사용할 실행 모드를 결정합니다.
     *
     * @param  ExecutionMode|null  $requested  호출부 명시 모드 (null/Auto 면 설정 → 감지 순서로 진행)
     * @return ExecutionMode Subprocess 또는 Inprocess (Auto 는 반환되지 않는다)
     */
    public function resolveMode(?ExecutionMode $requested = null): ExecutionMode
    {
        // 1. 호출부 명시
        if ($requested !== null && $requested !== ExecutionMode::Auto) {
            return $requested;
        }

        // 2. 운영자 저장 설정 (기본값 auto — 명시 고정한 경우에만 감지를 건너뛴다)
        $configured = ExecutionMode::fromStringOrAuto(
            (string) g7_core_settings('system.execution_mode', 'auto'),
        );
        if ($configured !== ExecutionMode::Auto) {
            return $configured;
        }

        // 3. 환경 감지 — 불확실 시 subprocess 로 떨어지는 판정은 ExecutionEnvironment 가 소유
        $report = $this->detectionReport();

        return $report['mode'] === ExecutionEnvironment::MODE_INPROCESS
            ? ExecutionMode::Inprocess
            : ExecutionMode::Subprocess;
    }

    /**
     * 결정된 모드의 러너를 반환합니다.
     *
     * @param  ExecutionMode|null  $requested  호출부 명시 모드
     * @return CommandRunner 결정된 모드의 러너 인스턴스
     */
    public function resolve(?ExecutionMode $requested = null): CommandRunner
    {
        return match ($this->resolveMode($requested)) {
            ExecutionMode::Inprocess => new InProcessCommandRunner,
            default => new SubprocessCommandRunner,
        };
    }

    /**
     * 환경 감지 결과(판정 근거 포함)를 반환합니다.
     *
     * 관리자 화면의 "현재 실행 모드 + 판정 근거" 표시와 재감지 버튼이 이 결과를 소비한다.
     *
     * @return array{mode: string, blocked_functions: array<int, string>, php_binary: string, php_probe: array{status: string, version: string|null, message: string}} 감지 결과
     */
    public function detectionReport(): array
    {
        $phpBinary = (string) (config('process.php_binary', 'php') ?: 'php');

        if ($this->detector !== null) {
            return ($this->detector)($phpBinary);
        }

        return ExecutionEnvironment::detect($phpBinary);
    }
}
