<?php

namespace App\Execution;

use App\Execution\Contracts\CommandRunner;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * In-process 커맨드 러너.
 *
 * 같은 PHP 프로세스 안에서 `Artisan::call()` 로 커맨드를 실행한다.
 * CLI 스폰이 불가능한 환경(disable_functions 차단, PHP 바이너리 부재)용 신규 경로 —
 * 신규 코드는 전부 이 분기 안에 있고, 기존 서브프로세스 경로는 건드리지 않는다.
 *
 * 대화형 프롬프트는 성립하지 않으므로 호출자는 비대화형 인자를 명시 전달해야 한다.
 */
class InProcessCommandRunner implements CommandRunner
{
    /**
     * 이 러너의 실행 모드.
     *
     * @return ExecutionMode 항상 Inprocess
     */
    public function mode(): ExecutionMode
    {
        return ExecutionMode::Inprocess;
    }

    /**
     * Artisan 커맨드를 같은 프로세스에서 실행합니다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  커맨드 파라미터 (Artisan::call 규약)
     * @return CommandRunResult 실행 결과
     */
    public function run(string $command, array $parameters = []): CommandRunResult
    {
        $output = new BufferedOutput;

        // 비대화형 강제 — 웹 요청 안에는 응답할 TTY 가 없다
        $parameters['--no-interaction'] = true;

        $exitCode = Artisan::call($command, $parameters, $output);

        return new CommandRunResult($exitCode, $output->fetch(), ExecutionMode::Inprocess);
    }
}
