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
        return $this->decide($requested)['mode'];
    }

    /**
     * 결정된 모드의 러너를 반환합니다.
     *
     * 판정이 환경 감지로 내려진 subprocess 면 감지가 확인한 PHP 바이너리
     * (자동 탐색 폴백이 찾은 경로 포함)를 러너에 넘긴다 — 판정과 실행이 같은
     * 바이너리를 본다 (D35). 명시·설정으로 고정된 subprocess 는 설정값을 그대로 쓴다.
     *
     * @param  ExecutionMode|null  $requested  호출부 명시 모드
     * @return CommandRunner 결정된 모드의 러너 인스턴스
     */
    public function resolve(?ExecutionMode $requested = null): CommandRunner
    {
        $decision = $this->decide($requested);

        return match ($decision['mode']) {
            ExecutionMode::Inprocess => new InProcessCommandRunner,
            default => new SubprocessCommandRunner($decision['report']['php_binary'] ?? null),
        };
    }

    /**
     * 환경 감지 결과(판정 근거 포함)를 반환합니다.
     *
     * 관리자 화면의 "현재 실행 모드 + 판정 근거" 표시와 재감지 버튼이 이 결과를 소비할
     * **예정**이다 (P5 공정). 현재 이 저장소의 호출자는 자기 자신과 테스트뿐이므로 현재형
     * 서술을 고쳤다 — 「이미 소비된다」로 읽히면 그 화면이 없다는 사실이 묻힌다
     * (7차 G3 라운드 D-5).
     *
     * `php_probe.message` 는 진단용 원문이고, `message_key` 는 그 사유의 번역 키다. 화면에
     * 내보낼 때는 키를 쓴다 — 원문은 코어가 만든 문자열이라 로케일을 따르지 않는다.
     *
     * @return array{mode: string, blocked_functions: array<int, string>, php_binary: string, php_probe: array{status: string, version: string|null, sapi: string|null, message: string, message_key: string}} 감지 결과
     */
    public function detectionReport(): array
    {
        $phpBinary = (string) (config('process.php_binary', 'php') ?: 'php');

        if ($this->detector !== null) {
            return ($this->detector)($phpBinary);
        }

        return ExecutionEnvironment::detect($phpBinary);
    }

    /**
     * 모드 판정과 그 근거를 함께 돌려줍니다 (resolveMode/resolve 공용).
     *
     * `report` 는 판정이 환경 감지로 내려졌을 때만 채워진다 — 명시·설정 고정 시 null.
     *
     * @param  ExecutionMode|null  $requested  호출부 명시 모드
     * @return array{mode: ExecutionMode, report: array<string, mixed>|null} 판정 결과
     */
    private function decide(?ExecutionMode $requested): array
    {
        // 1. 호출부 명시
        if ($requested !== null && $requested !== ExecutionMode::Auto) {
            return ['mode' => $requested, 'report' => null];
        }

        // 2. 운영자 저장 설정 (기본값 auto — 명시 고정한 경우에만 감지를 건너뛴다)
        $configured = ExecutionMode::fromStringOrAuto(
            (string) g7_core_settings('system.execution_mode', 'auto'),
        );
        if ($configured !== ExecutionMode::Auto) {
            return ['mode' => $configured, 'report' => null];
        }

        // 3. 환경 감지 — 불확실 시 subprocess 로 떨어지는 판정은 ExecutionEnvironment 가 소유
        $report = $this->detectionReport();

        return [
            'mode' => $report['mode'] === ExecutionEnvironment::MODE_INPROCESS
                ? ExecutionMode::Inprocess
                : ExecutionMode::Subprocess,
            'report' => $report,
        ];
    }
}
