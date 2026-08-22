<?php

namespace Tests\Unit\Support;

use App\Support\ExecutionEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * 실행 환경 판정(ExecutionEnvironment) 단위 테스트.
 *
 * disable_functions 는 INI 값이라 테스트 런타임에서 재현할 수 없으므로(§8 자동화 한계)
 * 판정 분기는 콜백 주입으로 검증한다. 실제 차단 환경의 종단 검증은 P0 프로필의
 * 브라우저 실측이 담당한다.
 */
class ExecutionEnvironmentTest extends TestCase
{
    /**
     * 함수 전부 가용 + probe 통과 → subprocess (현행 경로 유지).
     */
    public function test_detect_returns_subprocess_when_functions_available_and_probe_valid(): void
    {
        $result = ExecutionEnvironment::detect(
            'php',
            validator: fn () => ['valid' => true, 'version' => '8.3.0', 'message' => 'ok'],
            hasFunction: fn () => true,
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame([], $result['blocked_functions']);
        $this->assertSame('valid', $result['php_probe']['status']);
    }

    /**
     * 필수 함수 차단 → 서브프로세스가 명확히 불가 → inprocess.
     */
    public function test_detect_returns_inprocess_when_functions_blocked(): void
    {
        $result = ExecutionEnvironment::detect(
            'php',
            hasFunction: fn (string $fn) => false,
        );

        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, $result['mode']);
        $this->assertSame(ExecutionEnvironment::SUBPROCESS_FUNCTIONS, $result['blocked_functions']);
    }

    /**
     * 일부 함수만 차단되어도 서브프로세스 요건 미충족 → inprocess.
     */
    public function test_detect_returns_inprocess_when_single_function_blocked(): void
    {
        $result = ExecutionEnvironment::detect(
            'php',
            hasFunction: fn (string $fn) => $fn !== 'proc_open',
        );

        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, $result['mode']);
        $this->assertSame(['proc_open'], $result['blocked_functions']);
    }

    /**
     * 함수 가용 + probe 명확 실패 + 자동 탐색도 실패 → inprocess.
     */
    public function test_detect_returns_inprocess_when_probe_invalid_and_no_autodetect(): void
    {
        $result = ExecutionEnvironment::detect(
            'php',
            validator: fn () => ['valid' => false, 'version' => null, 'message' => 'not found'],
            hasFunction: fn () => true,
            autoDetector: fn () => [],
        );

        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, $result['mode']);
        $this->assertSame('invalid', $result['php_probe']['status']);
    }

    /**
     * 지정 바이너리 probe 실패 + 공통 경로 자동 탐색 성공 → subprocess.
     * (PATH 에 php 가 없고 비표준 경로에만 있는 호스팅을 오판하지 않는다)
     */
    public function test_detect_falls_back_to_autodetected_binary(): void
    {
        $result = ExecutionEnvironment::detect(
            'php',
            validator: fn () => ['valid' => false, 'version' => null, 'message' => 'not found'],
            hasFunction: fn () => true,
            autoDetector: fn () => [['path' => '/usr/local/php83/bin/php', 'version' => '8.3.1']],
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame('/usr/local/php83/bin/php', $result['php_binary']);
        $this->assertSame('valid', $result['php_probe']['status']);
    }

    /**
     * probe 판정 불확실(unavailable) → fail-safe 는 subprocess (기존 환경 보호).
     */
    public function test_detect_fail_safe_to_subprocess_when_probe_uncertain(): void
    {
        $result = ExecutionEnvironment::detect(
            'php',
            validator: fn () => throw new \RuntimeException('probe crashed'),
            hasFunction: fn () => true,
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame('unavailable', $result['php_probe']['status']);
    }

    /**
     * probe: exec 차단 환경에서는 실행 없이 unavailable(불확실) 을 돌려준다.
     */
    public function test_probe_returns_unavailable_when_exec_blocked(): void
    {
        $probe = ExecutionEnvironment::probePhpBinary(
            'php',
            hasFunction: fn (string $fn) => false,
        );

        $this->assertSame('unavailable', $probe['status']);
        $this->assertNull($probe['version']);
    }

    /**
     * 서브프로세스 필수 함수 집합은 인스톨러 게이트의 현행 집합과 동일해야 한다.
     * (집합 변경 = 설치 게이트 판정 변경 — 의도 없는 변경을 차단)
     */
    public function test_subprocess_function_set_is_stable(): void
    {
        $this->assertSame(['exec', 'proc_open', 'shell_exec'], ExecutionEnvironment::SUBPROCESS_FUNCTIONS);
    }
}
