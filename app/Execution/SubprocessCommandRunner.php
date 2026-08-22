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
 */
class SubprocessCommandRunner implements CommandRunner
{
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

        $phpBinary = config('process.php_binary', PHP_BINARY) ?: PHP_BINARY;
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
        $env = array_merge(getenv(), $_ENV);

        $process = @proc_open($commandLine, $descriptors, $pipes, base_path(), $env);
        if (! is_resource($process)) {
            throw new CommandExecutionException('exceptions.execution.spawn_failed', [
                'command' => $command,
            ]);
        }

        fclose($pipes[0]);

        $output = '';
        while (! feof($pipes[1])) {
            $chunk = fgets($pipes[1]);
            if ($chunk !== false) {
                $output .= $chunk;
            }
        }

        fclose($pipes[1]);
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);

        return new CommandRunResult($exitCode, $output, ExecutionMode::Subprocess);
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
