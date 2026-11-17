<?php

namespace App\Console\Helpers;

use App\Support\PhpBinaryResolver;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * 다른 Artisan 명령을 실행하고 종료 코드와 출력을 돌려줍니다 — 여러 명령을 차례로 부르는 명령의 실행 지점.
 *
 * 출력은 따로 받는다 — 안쪽 명령이 또 다른 명령(route:clear 등)을 부르면 `Artisan::output()` 이 그 출력으로 바뀐다.
 * 시험은 컨테이너에서 이 클래스를 바꿔 끼워 실제 명령을 돌리지 않고 호출 순서·인자·종료 코드를 확인한다.
 *
 * @since 7.2.0
 */
class ArtisanCommandRunner
{
    /**
     * 명령을 이 프로세스에서 실행합니다.
     *
     * @param  string  $command  명령 이름
     * @param  array<string, mixed>  $parameters  인자·옵션
     * @return array{exit_code: int, output: string} 종료 코드와 출력
     */
    public function run(string $command, array $parameters = []): array
    {
        $buffer = new BufferedOutput;
        $exitCode = Artisan::call($command, $parameters, $buffer);

        return ['exit_code' => (int) $exitCode, 'output' => $buffer->fetch()];
    }

    /**
     * 명령을 새 PHP 프로세스로 실행합니다.
     *
     * 같은 프로세스는 부팅 때 불러온 확장 클래스·선언을 그대로 쓴다. 앞 단계가 활성 폴더 파일을 바꿨으면 뒤 단계(레이아웃 갱신·
     * 선언 정리)는 새 프로세스로 돌려 바뀐 파일의 선언으로 계산한다.
     *
     * @param  string  $command  명령 이름
     * @param  array<string, mixed>  $parameters  인자·옵션 (`--옵션` => true|값, 그 밖 키는 위치 인자)
     * @return array{exit_code: int, output: string} 종료 코드와 출력(표준 출력 + 표준 오류)
     */
    public function runFresh(string $command, array $parameters = []): array
    {
        $argv = [PhpBinaryResolver::resolve(), base_path('artisan'), $command];
        foreach ($parameters as $key => $value) {
            if (is_string($key) && str_starts_with($key, '--')) {
                if ($value === true) {
                    $argv[] = $key;
                } elseif ($value !== false && $value !== null) {
                    $argv[] = $key.'='.$value;
                }
            } else {
                $argv[] = (string) $value;
            }
        }
        $argv[] = '--no-interaction';

        $process = new Process($argv, base_path());
        $process->setTimeout(null);
        $process->run();

        return ['exit_code' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    }
}
