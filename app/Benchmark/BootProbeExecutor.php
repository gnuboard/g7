<?php

namespace App\Benchmark;

use Symfony\Component\Process\Process;

/**
 * 부팅 축 자식 프로세스 실행기
 *
 * 부팅 비용은 이미 부팅된 앱 안에서는 잴 수 없으므로 회차마다 새 PHP 프로세스를 띄웁니다.
 * 프로세스 실행을 이 클래스로 분리한 이유는 실행기 테스트가 실제 프로세스 없이 가짜 출력으로
 * 파싱·집계를 검증하게 하기 위해서입니다.
 */
class BootProbeExecutor
{
    /**
     * 자식 프로세스 1회 실행 제한 시간(초)
     */
    private const TIMEOUT_SECONDS = 120;

    /**
     * 명령을 새 프로세스로 실행합니다.
     *
     * @param  array<int, string>  $command  실행할 명령과 인자
     * @param  array<string, string>  $env  자식 환경 변수 (부모 환경에 덧붙임)
     * @param  string  $cwd  작업 디렉토리
     * @return array{exit_code: int|null, stdout: string, stderr: string} 실행 결과
     */
    public function run(array $command, array $env, string $cwd): array
    {
        $process = new Process($command, $cwd, $env, null, self::TIMEOUT_SECONDS);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
