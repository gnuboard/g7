<?php

namespace App\Execution\Contracts;

use App\Execution\CommandRunResult;
use App\Execution\Exceptions\CommandExecutionException;
use App\Execution\ExecutionMode;

/**
 * 웹 요청 안에서 Artisan 커맨드를 실행하는 러너 계약.
 *
 * 진입 경계: 이 러너는 **웹 요청에서 출발하는 커맨드 실행**에만 쓰인다.
 * 콘솔(artisan)에서 들어온 실행은 이미 CLI 프로세스 안이므로 러너를 거치지 않는다 —
 * 이 경계는 주석이 아니라 호출 지점 자체로 표현한다 (콘솔 커맨드 코드는 러너를 주입받지 않는다).
 */
interface CommandRunner
{
    /**
     * 이 러너의 실행 모드.
     *
     * @return ExecutionMode Subprocess 또는 Inprocess (Auto 는 러너가 될 수 없다)
     */
    public function mode(): ExecutionMode;

    /**
     * Artisan 커맨드를 실행합니다.
     *
     * 파라미터 배열은 `Artisan::call()` 규약을 따른다 —
     * `['--force' => true, '--from' => '1.0.0', 'name' => 'value']`.
     * 두 러너가 같은 파라미터 표기를 받아 같은 의미로 실행하는 것이
     * 등가 계약 테스트로 고정된다.
     *
     * @param  string  $command  Artisan 커맨드명 (예: 'migrate')
     * @param  array<string|int, mixed>  $parameters  커맨드 파라미터 (Artisan::call 규약)
     * @return CommandRunResult 실행 결과
     *
     * @throws CommandExecutionException 실행 자체가 불가능한 경우 (스폰 실패 등)
     */
    public function run(string $command, array $parameters = []): CommandRunResult;
}
