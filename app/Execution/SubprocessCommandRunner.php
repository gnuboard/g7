<?php

namespace App\Execution;

use App\Execution\Contracts\CommandRunner;
use App\Execution\Exceptions\CommandExecutionException;
use App\Support\ExecutionEnvironment;

/**
 * 서브프로세스 커맨드 러너 (현행 경로).
 *
 * `php artisan {command} …` 를 자식 프로세스로 스폰한다. 스폰 구성(명령 조립·
 * env 전달·작업 디렉토리)은 기존 spawn 지점(CoreUpdateCommand::spawnUpgradeStepsProcess)
 * 의 본문을 그대로 따른다 — 로직을 새로 발명하지 않는다 (A-0 회귀 보장 1).
 *
 * PHP 바이너리는 해석기가 판정 결과(`detectionReport()['php_binary']`)로 넘긴 값을
 * 우선 쓴다 — PATH 의 `php` 가 실행 불가하고 비표준 경로에만 있는 호스팅에서
 * 판정(자동 탐색 성공 → subprocess)과 실행(설정값 `php`)이 어긋나지 않게 한다 (D35).
 * 명시 전달이 없으면 설정값 `process.php_binary` 로 폴백한다 (기본값 불변 — 해석기와 같은 폴백).
 */
class SubprocessCommandRunner implements CommandRunner
{
    /**
     * @param  string|null  $phpBinary  스폰에 쓸 PHP 바이너리 경로 (null 이면 설정값 폴백)
     */
    public function __construct(
        private readonly ?string $phpBinary = null,
    ) {}

    /**
     * 이 러너의 실행 모드.
     *
     * @return ExecutionMode 항상 Subprocess
     */
    public function mode(): ExecutionMode
    {
        return ExecutionMode::Subprocess;
    }

    /**
     * 스폰에 실제로 쓰이는 PHP 바이너리 경로.
     *
     * 해석기가 넘긴 경로가 있으면 그것, 없으면 설정값(`process.php_binary`, 기본 'php').
     *
     * @return string PHP 바이너리 경로
     */
    public function phpBinary(): string
    {
        if ($this->phpBinary !== null && $this->phpBinary !== '') {
            return $this->phpBinary;
        }

        // 해석기(CommandRunnerResolver::detectionReport)와 같은 폴백을 쓴다 — 어긋나면
        // `PHP_BINARY=` (빈 값) 같은 설정에서 판정은 'php' 를, 실행은 현재 SAPI 바이너리를
        // 가리켜 두 축이 다른 대상을 말한다.
        return (string) (config('process.php_binary', 'php') ?: 'php');
    }

    /**
     * Artisan 커맨드를 자식 프로세스로 실행합니다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  커맨드 파라미터 (Artisan::call 규약)
     * @return CommandRunResult 실행 결과
     *
     * @throws CommandExecutionException proc_open 불가·자원 생성 실패 시
     */
    public function run(string $command, array $parameters = []): CommandRunResult
    {
        if (! ExecutionEnvironment::hasFunction('proc_open')) {
            throw new CommandExecutionException('exceptions.execution.spawn_unavailable', [
                'reason' => 'proc_open disabled',
            ]);
        }

        $phpBinary = $this->phpBinary();
        $artisan = base_path('artisan');

        $argv = array_merge(
            [$phpBinary, $artisan, $command],
            self::formatParameters($parameters),
            // 비대화형 강제 — 웹 요청 안에는 응답할 TTY 가 없다 (in-process 러너와 동일 규약)
            ['--no-interaction'],
        );

        $commandLine = implode(' ', array_map('escapeshellarg', $argv)).' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // spawn 자식 env — getenv() 를 기반으로 $_ENV 로 보완 (variables_order 에 E 가
        // 없는 호스팅에서 $_ENV 가 비는 문제 대응. CoreUpdateCommand spawn 과 동일 구성).
        //
        // `getenv` 는 공유 호스팅이 `disable_functions` 로 흔히 막는 함수다. PHP 8 은 차단
        // 함수를 함수 테이블에서 제거하므로 무가드 호출은 `Error` 로 죽는다 — `proc_open`
        // 만 가드해 둔 상태에서는 그 환경에서 이 줄이 러너 전체를 무너뜨린다.
        $env = self::inheritedEnvironment();

        $process = @proc_open($commandLine, $descriptors, $pipes, base_path(), $env);
        if (! is_resource($process)) {
            throw new CommandExecutionException('exceptions.execution.spawn_failed', [
                'command' => $command,
            ]);
        }

        fclose($pipes[0]);

        $output = self::readUntilEof($pipes[1], $process);

        fclose($pipes[1]);
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);

        return new CommandRunResult($exitCode, $output, ExecutionMode::Subprocess);
    }

    /**
     * 자식 프로세스에 물려줄 환경변수 배열.
     *
     * `getenv` 가 차단된 환경에서는 `$_SERVER` 로 대체한다 — `$_ENV` 만으로는
     * `variables_order` 에 `E` 가 없는 호스팅에서 비어 버려 자식이 PATH 조차 못 받는다.
     *
     * @return array<string, string> 환경변수 맵
     */
    private static function inheritedEnvironment(): array
    {
        $base = [];

        if (ExecutionEnvironment::hasFunction('getenv')) {
            $fromGetenv = getenv();
            if (is_array($fromGetenv)) {
                $base = $fromGetenv;
            }
        } else {
            // `$_SERVER` 는 SAPI 가 채우는 배열이라 요청 메타(REQUEST_URI 등)가 섞인다.
            // 자식에게 넘겨도 해롭지 않지만, 스칼라 문자열만 추린다.
            foreach ($_SERVER as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $base[$key] = $value;
                }
            }
        }

        return array_merge($base, $_ENV);
    }

    /**
     * 자식 표준출력을 EOF 까지 읽되, 예산을 초과하면 프로세스를 끊습니다.
     *
     * 블로킹 `fgets()` 루프는 자식이 응답하지 않으면 **무기한** 멈춘다. Unix 에서
     * `max_execution_time` 은 시스템 콜 대기를 세지 않으므로 PHP 의 실행 제한도 이 대기를
     * 끝내지 못한다. 그 사이 워커 lock 은 heartbeat 없이 stale 로 넘어가 다른 요청이
     * 같은 작업을 겹쳐 실행한다.
     *
     * @param  resource  $pipe  자식 stdout 파이프
     * @param  resource  $process  proc_open 핸들 (예산 초과 시 종료 대상)
     * @param  float|null  $budgetSeconds  대기 상한 (테스트 주입용, 기본: readTimeoutSeconds())
     * @return string 수집된 출력
     */
    private static function readUntilEof($pipe, $process, ?float $budgetSeconds = null): string
    {
        $budget = $budgetSeconds ?? self::readTimeoutSeconds();
        $deadline = microtime(true) + $budget;
        $output = '';

        // Windows 는 proc_open 파이프의 비블로킹 전환을 지원하지 않는다 —
        // `stream_set_blocking()` 이 false 를 돌려주고, `stream_select()` 는 대기 없이 늘
        // "읽을 수 있음" 을 돌려주며, `fread()` 는 그대로 블로킹한다(실측). 그 환경에서는
        // 예산을 지킬 방법이 없으므로 종전 블로킹 읽기를 그대로 쓴다. 대신 Windows 의
        // `max_execution_time` 은 벽시계 기준 타이머 스레드가 강제하므로 요청이 영원히
        // 멈추지는 않는다 — 예산이 필요한 쪽은 시스템 콜 대기를 세지 않는 Unix 다.
        if (@stream_set_blocking($pipe, false) === false) {
            while (! feof($pipe)) {
                $chunk = fgets($pipe);
                if ($chunk !== false) {
                    $output .= $chunk;
                }
            }

            return $output;
        }

        while (! feof($pipe)) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $output .= "\n[execution] output read timed out after {$budget}s — child terminated\n";
                @proc_terminate($process);
                break;
            }

            $read = [$pipe];
            $write = [];
            $except = [];

            // 남은 예산을 초 + 마이크로초로 쪼갠다. 대기 상한이 있어야 `feof` 가 영영
            // 참이 되지 않는 자식에서도 루프가 끝난다.
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1000000);

            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($pipe, 8192);

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                // Windows 는 proc_open 파이프에 대한 stream_select 가 대기하지 않고 즉시
                // "읽을 수 있음" 을 돌려준다. 그대로 두면 자식이 침묵하는 동안 CPU 를 태운다.
                usleep(1000);

                continue;
            }

            $output .= $chunk;
        }

        return $output;
    }

    /**
     * 자식 출력 대기 예산(초).
     *
     * PHP 실행 제한을 따르고, 그것이 무제한(0 — CLI 기본값)이면 보수적 기본값을 쓴다.
     * 새 설정 키를 만들지 않는다 — 이 값은 "영원히 멈추지 않는다" 를 보장하는 상한일 뿐
     * 운영자가 조율할 대상이 아니고, 키를 늘리면 확장이 참조할 공개 표면이 하나 더 생긴다.
     *
     * @return float 대기 상한 (초)
     */
    private static function readTimeoutSeconds(): float
    {
        // `ini_get` 도 차단 대상이다 — 형제 `OpcacheStatus::probe()` 가 이미 그 전제로 서 있다.
        // 못 읽으면 「무제한」과 같이 다루어 보수적 기본값으로 내려간다.
        $limit = ExecutionEnvironment::hasFunction('ini_get') ? (int) ini_get('max_execution_time') : 0;

        if ($limit <= 0) {
            return 300.0;
        }

        // 제한과 **같은** 값을 쓰면 소용이 없다 — 대기 중에 PHP 가 먼저 프로세스를 죽여
        // 자식을 정리할 기회도, 사유를 남길 기회도 사라진다. 8할만 쓰고 나머지는 뒷정리 몫.
        return max(0.5, $limit * 0.8);
    }

    /**
     * Artisan::call 규약의 파라미터 배열을 CLI 인자 목록으로 변환합니다.
     *
     * - `'--force' => true` → `--force` / `'--force' => false` → (생략)
     * - `'--from' => '1.0.0'` → `--from=1.0.0`
     * - `'--path' => ['a', 'b']` → `--path=a --path=b`
     * - `0 => 'value'` (숫자 키) → `value`
     * - `'name' => 'value'` (인자명 키) → `value` (위치 인자로 전달)
     *
     * 두 러너의 파라미터 해석 등가는 계약 테스트가 고정한다.
     *
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     * @return array<int, string> CLI 인자 목록 (escapeshellarg 적용 전)
     */
    public static function formatParameters(array $parameters): array
    {
        $args = [];

        foreach ($parameters as $key => $value) {
            if (is_string($key) && str_starts_with($key, '--')) {
                if (is_bool($value)) {
                    if ($value) {
                        $args[] = $key;
                    }

                    continue;
                }

                foreach ((array) $value as $item) {
                    $args[] = $key.'='.$item;
                }

                continue;
            }

            // 위치 인자 (숫자 키 또는 인자명 키) — 값만 전달
            foreach ((array) $value as $item) {
                $args[] = (string) $item;
            }
        }

        return $args;
    }
}
