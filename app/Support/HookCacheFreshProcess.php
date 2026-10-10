<?php

namespace App\Support;

/**
 * 훅 캐시를 **새 PHP 프로세스**에서 다시 굽는 헬퍼.
 *
 * 코어 업데이트(`core:update`)는 이전 코드로 부팅한 프로세스에서 끝까지 돈다. 업데이트 시작 시점에
 * 훅 캐시(bootstrap/cache/hooks.php)가 없던 사이트는 부팅 때 이전 리스너 클래스가 메모리에 올라와,
 * 흐름 중간의 캐시 재생성이 이전 구독(예: sync 없는 큐 재시작 리스너)으로 구워질 수 있다. 번들 확장
 * 일괄 업데이트도 같은 프로세스에서 확장마다 캐시를 다시 구우므로 같은 문제를 되살린다. 새 프로세스는
 * 디스크의 새 코드로 부팅하므로 이 상태를 확정적으로 없앤다 — 코어 업데이트는 정상 종료·일괄 업데이트 뒤·
 * 핸드오프 종료에서 이 헬퍼를 부른다(#138 후속 — 설계 결정).
 *
 * 실패해도 업데이트를 되돌리지 않는다 — 이미 새 코드가 안착했으므로 운영자에게 수동 명령을 안내한다.
 */
class HookCacheFreshProcess
{
    /**
     * 새 프로세스에서 Artisan 명령을 실행합니다.
     *
     * @param  array<int, string>  $arguments  artisan 뒤 인자 (기본: hooks:cache)
     * @return array{ok: bool, exit_code: int|null, output: string} 실행 결과
     */
    public static function run(array $arguments = ['hooks:cache']): array
    {
        if (! function_exists('proc_open')) {
            return ['ok' => false, 'exit_code' => null, 'output' => 'proc_open 비활성'];
        }

        $command = [config('process.php_binary', PHP_BINARY), base_path('artisan'), ...$arguments];
        $commandLine = implode(' ', array_map('escapeshellarg', $command)).' 2>&1';

        $process = proc_open($commandLine, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), $_ENV));
        if (! is_resource($process)) {
            return ['ok' => false, 'exit_code' => null, 'output' => 'proc_open 자원 생성 실패'];
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return ['ok' => $exitCode === 0, 'exit_code' => $exitCode, 'output' => trim($output)];
    }
}
