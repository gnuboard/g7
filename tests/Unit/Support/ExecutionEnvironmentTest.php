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
 *
 * 커버 축: function_availability all_available·one_blocked·all_blocked / requested_mode none·auto·subprocess·inprocess
 */
class ExecutionEnvironmentTest extends TestCase
{
    /**
     * 함수 전부 가용 + probe 통과 → subprocess (현행 경로 유지).
     *
     * @effects detect_returns_subprocess_when_functions_available_and_probe_valid
     *
     * @return void 반환값 없음
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
     *
     * @effects detect_returns_inprocess_when_functions_blocked
     *
     * @return void 반환값 없음
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
     *
     * @effects detect_returns_inprocess_when_single_function_blocked
     *
     * @return void 반환값 없음
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
     *
     * @effects detect_returns_inprocess_when_probe_invalid_and_no_autodetect
     *
     * @return void 반환값 없음
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
     *
     * @effects detect_falls_back_to_autodetected_binary
     *
     * @return void 반환값 없음
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
     *
     * @effects detect_fail_safe_to_subprocess_when_probe_uncertain
     *
     * @return void 반환값 없음
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
     *
     * @effects probe_returns_unavailable_when_exec_blocked
     *
     * @return void 반환값 없음
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
     *
     * @effects subprocess_function_set_is_stable
     *
     * @return void 반환값 없음
     */
    public function test_subprocess_function_set_is_stable(): void
    {
        $this->assertSame(['exec', 'proc_open', 'shell_exec'], ExecutionEnvironment::SUBPROCESS_FUNCTIONS);
    }

    /**
     * probe 는 SAPI 를 판정 결과에 실어 올린다.
     *
     * `php-fpm --version` · `php-cgi --version` 은 CLI 와 똑같이 정상 종료하고 같은 형태로
     * 버전을 찍는다. 그래서 버전만 보면 valid 지만 그 바이너리는 artisan 을 받지 못한다 —
     * SAPI 가 그 둘을 가르는 유일한 신호이므로 버리지 않는다.
     *
     * @effects validate_reports_sapi_for_configured_binary
     *
     * @return void 반환값 없음
     */
    public function test_probe_reports_sapi(): void
    {
        $probe = ExecutionEnvironment::probePhpBinary(
            '/usr/bin/php-fpm',
            validator: fn () => ['valid' => true, 'version' => '8.3.0', 'sapi' => 'fpm', 'message' => 'ok'],
            hasFunction: fn () => true,
        );

        $this->assertSame('valid', $probe['status']);
        $this->assertSame('fpm', $probe['sapi']);
    }

    /**
     * 운영자가 **명시**한 바이너리가 비-CLI SAPI 면 CLI 후보를 먼저 찾는다.
     *
     * 자동 탐색 경로는 CLI 우선 정렬로 이미 닫혔지만(3차 라운드), 명시 경로는 그대로였다 —
     * Step 3 이나 process.php_binary 에 FPM/CGI 를 넣으면 판정은 subprocess 인데 태스크는
     * 전부 실패하는 조합이 남는다.
     *
     * @effects auto_detect_orders_cli_binaries_first
     *
     * @return void 반환값 없음
     */
    public function test_detect_prefers_cli_binary_when_configured_binary_is_not_cli(): void
    {
        $result = ExecutionEnvironment::detect(
            '/usr/sbin/php-fpm',
            validator: fn () => ['valid' => true, 'version' => '8.3.0', 'sapi' => 'fpm', 'message' => 'ok'],
            hasFunction: fn () => true,
            autoDetector: fn () => [['path' => '/usr/bin/php', 'version' => '8.3.0', 'sapi' => 'cli']],
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame('/usr/bin/php', $result['php_binary'], 'CLI 후보가 있으면 그것을 쓴다');
    }

    /**
     * 단, CLI 후보가 하나도 없으면 명시한 바이너리를 그대로 쓴다.
     *
     * php-cgi 로만 동작하던 환경을 새로 막지 않는다 — 자동 탐색 경로의 정렬 규칙과 같은 원칙이다.
     *
     * @effects auto_detect_orders_cli_binaries_first
     *
     * @return void 반환값 없음
     */
    public function test_detect_keeps_non_cli_binary_when_no_cli_candidate_exists(): void
    {
        $result = ExecutionEnvironment::detect(
            '/usr/bin/php-cgi',
            validator: fn () => ['valid' => true, 'version' => '8.3.0', 'sapi' => 'cgi', 'message' => 'ok'],
            hasFunction: fn () => true,
            autoDetector: fn () => [],
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame('/usr/bin/php-cgi', $result['php_binary']);
    }

    /**
     * 자동 탐색이 **비-CLI 후보만** 돌려주면 운영자가 지정한 바이너리를 그대로 둔다.
     *
     * 위 테스트는 `autoDetector` 가 빈 배열이라 `$found !== []` 에서 먼저 빠져나가므로
     * 후보의 SAPI 를 평가하는 분기(`$candidateIsCli`)에 **도달하지 못한다** — 그 조건을
     * 통째로 지워 `if (true)` 로 만들어도 초록이었다. 여기서는 후보를 실제로 하나 주어
     * 그 분기를 태운다: 지정도 비-CLI, 후보도 비-CLI 면 바꿀 이유가 없으므로 교체하지 않는다
     * (docblock 이 약속한 "php-cgi 로만 동작하던 환경을 새로 막지 않는다").
     *
     * @effects auto_detect_orders_cli_binaries_first
     *
     * @return void 반환값 없음
     */
    public function test_detect_does_not_swap_a_non_cli_binary_for_another_non_cli_candidate(): void
    {
        $result = ExecutionEnvironment::detect(
            '/usr/bin/php-cgi',
            validator: fn () => ['valid' => true, 'version' => '8.3.0', 'sapi' => 'cgi', 'message' => 'ok'],
            hasFunction: fn () => true,
            autoDetector: fn () => [
                ['path' => '/opt/php/bin/php-fpm', 'version' => '8.3.1', 'sapi' => 'fpm-fcgi'],
            ],
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame('/usr/bin/php-cgi', $result['php_binary'], '비-CLI 를 다른 비-CLI 로 바꾸지 않는다');
    }

    /**
     * 지정이 비-CLI 인데 자동 탐색이 **CLI 후보**를 찾으면 그쪽으로 옮긴다.
     *
     * 위 테스트와 짝이다 — 두 방향을 함께 고정해야 `$candidateIsCli` 가 판정으로서 살아 있다.
     *
     * @effects auto_detect_orders_cli_binaries_first
     *
     * @return void 반환값 없음
     */
    public function test_detect_swaps_a_non_cli_binary_for_a_cli_candidate(): void
    {
        $result = ExecutionEnvironment::detect(
            '/usr/bin/php-cgi',
            validator: fn () => ['valid' => true, 'version' => '8.3.0', 'sapi' => 'cgi', 'message' => 'ok'],
            hasFunction: fn () => true,
            autoDetector: fn () => [
                ['path' => '/usr/local/bin/php', 'version' => '8.3.2', 'sapi' => 'cli'],
            ],
        );

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertSame('/usr/local/bin/php', $result['php_binary']);
        $this->assertSame('cli', $result['php_probe']['sapi']);
    }
}
