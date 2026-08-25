<?php

namespace App\Execution;

use App\Execution\Contracts\CommandRunner;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputDefinition;
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

        $parameters = $this->mapPositionalArguments($command, $parameters);

        // 비대화형 강제 — 웹 요청 안에는 응답할 TTY 가 없다
        $parameters['--no-interaction'] = true;

        $exitCode = Artisan::call($command, $parameters, $output);

        return new CommandRunResult($exitCode, $output->fetch(), ExecutionMode::Inprocess);
    }

    /**
     * 숫자 키 위치 인자(`[0 => 'value']`)를 커맨드 정의의 인자 **이름**에 매핑합니다.
     *
     * 서브프로세스는 argv 순서로 자연스럽게 전달되지만, `Artisan::call` 이 만드는 Symfony
     * ArrayInput 은 숫자 키를 그 키 그대로 저장해 필수 인자 검증(`validate`)이 인자를 찾지
     * 못한다 — "Not enough arguments (missing: …)". 두 러너가 같은 파라미터 표기를 받아
     * 같은 의미로 실행하려면 이 변환이 러너 안에 있어야 한다 (등가 계약 테스트가 고정).
     *
     * 인자명 키로 넘긴 값(`['name' => 'value']`)과 옵션(`'--opt' => …`)은 그대로 둔다.
     * 배열 인자(`{paths*}`)에는 남는 위치 값을 전부 모아 준다.
     *
     * @param  string  $command  Artisan 커맨드명
     * @param  array<string|int, mixed>  $parameters  Artisan::call 규약 파라미터
     * @return array<string|int, mixed> 위치 값이 인자명 키로 옮겨진 파라미터
     */
    private function mapPositionalArguments(string $command, array $parameters): array
    {
        $positional = [];
        $named = [];

        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $positional[] = $value;
            } else {
                $named[$key] = $value;
            }
        }

        if ($positional === []) {
            return $parameters;
        }

        $definition = $this->definitionOf($command);
        if ($definition === null) {
            // 모르는 커맨드 — Artisan 이 CommandNotFound 로 알리도록 원형 그대로 넘긴다
            return $parameters;
        }

        $arguments = array_values(array_filter(
            $definition->getArguments(),
            static fn ($argument): bool => $argument->getName() !== 'command',
        ));

        foreach ($positional as $index => $value) {
            $argument = $arguments[$index] ?? null;

            if ($argument === null) {
                // 정의보다 값이 많다 — 마지막 인자가 배열이면 거기에 덧붙이고, 아니면 Symfony 가 거부하게 둔다
                $last = $arguments !== [] ? $arguments[array_key_last($arguments)] : null;
                if ($last !== null && $last->isArray()) {
                    $named[$last->getName()] = array_merge((array) ($named[$last->getName()] ?? []), [$value]);

                    continue;
                }

                $named[$index] = $value;

                continue;
            }

            if ($argument->isArray()) {
                $named[$argument->getName()] = array_merge(
                    (array) ($named[$argument->getName()] ?? []),
                    array_slice($positional, $index),
                );
                break;
            }

            if (! array_key_exists($argument->getName(), $named)) {
                $named[$argument->getName()] = $value;
            }
        }

        return $named;
    }

    /**
     * 등록된 커맨드의 입력 정의를 돌려줍니다 (미등록이면 null).
     *
     * @param  string  $command  Artisan 커맨드명
     * @return InputDefinition|null 커맨드 자신의 정의 (애플리케이션 정의와 병합 전)
     */
    private function definitionOf(string $command): ?InputDefinition
    {
        $all = Artisan::all();

        if (! isset($all[$command])) {
            return null;
        }

        return $all[$command]->getDefinition();
    }
}
