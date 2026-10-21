<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Console\Commands\Core\ExecuteUpgradeStepsCommand;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 번들 확장 일괄 업데이트 실패가 `core:update`·단독 `core:execute-upgrade-steps` 의 종료 코드로 드러나는지 봉인한다 (#144).
 *
 * 종전에는 번들 확장이 실패해도 두 명령이 0 으로 끝났고, 결과를 남기지 못하고 죽은 spawn 자식은 "성공 0 / 실패 0" 으로
 * 세어졌다 — 자동화 스크립트가 실패를 알 길이 없었다.
 */
class BundledUpdateExitCodeTest extends TestCase
{
    /** 버퍼 출력 */
    private BufferedOutput $buffer;

    /**
     * 실패가 하나라도 있으면 종료 코드 1 과 경고 1줄, 없으면 0 이다.
     *
     * @scenario case=exit_code_by_failed_count
     *
     * @effects bundled_update_failure_returns_exit_code_one_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_exit_code_follows_failed_count(): void
    {
        $command = $this->commandWithBufferedOutput(CoreUpdateCommand::class);
        $method = new \ReflectionMethod($command, 'bundledUpdateExitCode');

        $this->assertSame(Command::SUCCESS, $method->invoke($command, ['success' => 3, 'failed' => 0], '7.1.1'));
        $this->assertSame('', trim($this->buffer->fetch()));

        $this->assertSame(Command::FAILURE, $method->invoke($command, ['success' => 2, 'failed' => 1], '7.1.1'));
        $output = $this->buffer->fetch();
        $this->assertStringContainsString(__('extensions.commands.bundled_update.exit_failure', ['version' => '7.1.1', 'failed' => 1]), $output);
    }

    /**
     * 두 명령의 정상 종료 경로가 같은 판정 메서드로 끝난다 (어느 한쪽만 0 으로 남지 않게).
     *
     * @scenario case=both_commands_route_through_exit_code
     *
     * @effects bundled_update_failure_returns_exit_code_one_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_both_commands_return_through_bundled_exit_code(): void
    {
        $this->assertStringContainsString(
            'return $this->bundledUpdateExitCode($promptResult, $toVersion);',
            file_get_contents(app_path('Console/Commands/Core/CoreUpdateCommand.php')),
        );
        $this->assertStringContainsString(
            'return $this->bundledUpdateExitCode($bundledResult, $to);',
            file_get_contents(app_path('Console/Commands/Core/ExecuteUpgradeStepsCommand.php')),
        );
    }

    /**
     * 결과 페이로드 없이 비정상 종료한 spawn 자식은 실패 1건으로 센다.
     *
     * 매니페스트를 JSON 으로 쓸 수 없는 값(잘못된 UTF-8)으로 만들면 빈 파일이 넘어가고, 자식은 판독 실패로 결과 줄 없이
     * 종료 코드 1 로 끝난다 — 결과를 못 남긴 비정상 종료의 실제 모양이다.
     *
     * @scenario case=spawn_child_exit_without_payload
     *
     * @effects spawn_child_crash_without_payload_counts_as_failure
     *
     * @return void 반환값 없음
     */
    public function test_spawn_child_exiting_without_payload_counts_as_failure(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        $command = $this->commandWithBufferedOutput(ExecuteUpgradeStepsCommand::class);
        $method = new \ReflectionMethod($command, 'spawnBundledUpdates');

        $result = $method->invoke($command, ['modules' => [['identifier' => "\xB1\x31"]], 'plugins' => [], 'templates' => [], 'lang_packs' => []]);

        $this->assertSame(['success' => 0, 'failed' => 1], $result);
    }

    /**
     * 출력을 버퍼로 받는 명령 인스턴스를 만듭니다.
     *
     * @param  class-string<Command>  $class  명령 클래스
     * @return Command 명령 인스턴스
     */
    private function commandWithBufferedOutput(string $class): Command
    {
        $command = app($class);
        $input = new ArrayInput([]);
        $this->buffer = new BufferedOutput;

        $command->setOutput(new OutputStyle($input, $this->buffer));
        $command->setInput($input);

        return $command;
    }
}
