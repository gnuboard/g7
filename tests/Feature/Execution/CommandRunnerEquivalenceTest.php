<?php

namespace Tests\Feature\Execution;

use App\Execution\ExecutionMode;
use App\Execution\InProcessCommandRunner;
use App\Execution\SubprocessCommandRunner;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

/**
 * 두 러너(서브프로세스·in-process)의 등가 계약 테스트.
 *
 * 같은 커맨드·같은 파라미터 표기가 두 러너에서 같은 의미로 실행되는 것을 고정한다 —
 * 한쪽 러너만 고치면 이 테스트가 red 가 된다 (A-0 회귀 보장 3).
 *
 * DB 를 건드리지 않는 안전한 커맨드(list/help)만 사용한다 — 서브프로세스는
 * 실제 `php artisan` 을 스폰하므로 부수효과 있는 커맨드는 여기서 돌리지 않는다.
 */
class CommandRunnerEquivalenceTest extends TestCase
{
    /**
     * 파라미터 표기 → CLI 인자 변환 계약 (Artisan::call 규약과 동일 의미).
     */
    public function test_format_parameters_matches_artisan_call_convention(): void
    {
        $this->assertSame(
            ['--force', '--from=1.0.0', '--path=a', '--path=b', 'positional', 'named-value'],
            SubprocessCommandRunner::formatParameters([
                '--force' => true,
                '--skip' => false,
                '--from' => '1.0.0',
                '--path' => ['a', 'b'],
                0 => 'positional',
                'name' => 'named-value',
            ]),
        );
    }

    /**
     * in-process 러너: 안전한 커맨드(list)가 성공하고 출력이 채워진다.
     */
    public function test_inprocess_runner_executes_safe_command(): void
    {
        $result = (new InProcessCommandRunner)->run('list');

        $this->assertSame(0, $result->exitCode);
        $this->assertTrue($result->successful());
        $this->assertNotSame('', trim($result->output));
        $this->assertSame(ExecutionMode::Inprocess, $result->mode);
    }

    /**
     * 등가 계약 — 같은 커맨드를 두 러너로 실행해 종료 상태가 일치한다.
     *
     * disable_functions 프로필(P0)에서는 서브프로세스 자체가 성립하지 않으므로
     * 그 환경에서는 측정 대상이 아니다 (무CLI 축 종단 검증은 브라우저 실측 — §8).
     */
    public function test_both_runners_agree_on_safe_command_exit_status(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 차단 환경 — 서브프로세스 러너는 이 환경의 측정 대상이 아니다 (P0 프로필)');
        }

        $inProcess = (new InProcessCommandRunner)->run('list', ['--format' => 'txt']);
        $subprocess = (new SubprocessCommandRunner)->run('list', ['--format' => 'txt']);

        $this->assertSame(0, $inProcess->exitCode);
        $this->assertSame(
            $inProcess->exitCode,
            $subprocess->exitCode,
            "두 러너의 종료 상태가 다릅니다.\nsubprocess output:\n".$subprocess->output,
        );
        $this->assertNotSame('', trim($subprocess->output));
    }

    /**
     * 등가 계약 — 존재하지 않는 커맨드는 두 러너 모두 비-0 으로 끝난다.
     * (실패를 성공으로 뭉개는 러너가 없어야 한다)
     */
    public function test_both_runners_agree_on_unknown_command_failure(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 차단 환경 — 서브프로세스 러너는 이 환경의 측정 대상이 아니다 (P0 프로필)');
        }

        $inProcessFailed = false;
        try {
            $result = (new InProcessCommandRunner)->run('g7:no-such-command-p1');
            $inProcessFailed = $result->exitCode !== 0;
        } catch (CommandNotFoundException) {
            $inProcessFailed = true;
        }

        $subprocess = (new SubprocessCommandRunner)->run('g7:no-such-command-p1');

        $this->assertTrue($inProcessFailed, 'in-process 러너가 미존재 커맨드를 성공 처리했습니다');
        $this->assertNotSame(0, $subprocess->exitCode, '서브프로세스 러너가 미존재 커맨드를 성공 처리했습니다');
    }
}
