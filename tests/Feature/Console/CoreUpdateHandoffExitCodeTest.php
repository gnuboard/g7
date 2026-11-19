<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Exceptions\UpgradeHandoffException;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `core:update` 가 업그레이드 스텝 핸드오프로 멈추면 종료 코드 75(재개 필요)로 끝난다 (#888).
 *
 * 종전에는 정리·재개 안내를 낸 뒤 0 으로 끝나 `php artisan core:update --yes -n; echo $?` 같은 스크립트가 성공으로 읽고
 * 재개 명령을 건너뛰었다. 단독 `core:execute-upgrade-steps` 는 같은 상황에서 이미 75 를 돌려준다.
 *
 * `handle()` 전체(다운로드·백업·파일 적용·마이그레이션)를 통과시키는 통합 실행은 사이드이펙트가 커서, 핸드오프 catch 가
 * 부르는 종료 코드 판정 메서드와 catch 블록의 반환문 계약을 검증한다 — 형제 계약 `BundledUpdateExitCodeTest` 와 같은 방식.
 */
class CoreUpdateHandoffExitCodeTest extends TestCase
{
    use DatabaseTransactions;

    /** 버퍼 출력 */
    private BufferedOutput $buffer;

    /**
     * 핸드오프 종료 코드는 75 이고, 재개가 필요하다는 안내 1줄을 남긴다.
     *
     * @scenario case=handoff_exit_code
     *
     * @effects core_update_handoff_returns_resume_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_handoff_returns_resume_exit_code(): void
    {
        $command = $this->commandWithBufferedOutput(CoreUpdateCommand::class);
        $method = new ReflectionMethod($command, 'handoffExitCode');

        $this->assertSame(75, UpgradeHandoffException::EXIT_CODE);
        $this->assertSame(UpgradeHandoffException::EXIT_CODE, $method->invoke($command));
        $this->assertStringContainsString(
            (string) __('settings.core_update.console.handoff_exit_code', ['code' => UpgradeHandoffException::EXIT_CODE]),
            $this->buffer->fetch(),
        );
    }

    /**
     * 핸드오프 catch 블록은 성공(0)이 아니라 핸드오프 종료 코드로 끝나고, 단독 실행과 같은 상수를 쓴다.
     *
     * @scenario case=handoff_catch_contract
     *
     * @effects core_update_handoff_returns_resume_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_handoff_catch_block_returns_resume_exit_code(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/Core/CoreUpdateCommand.php'));
        $start = strpos($source, 'catch (UpgradeHandoffException $e)');
        $this->assertNotFalse($start, '핸드오프 catch 블록이 있어야 한다');
        $end = strpos($source, 'catch (\Throwable $e)', $start);
        $this->assertNotFalse($end, '핸드오프 catch 다음의 일반 실패 catch 가 있어야 한다');
        $block = substr($source, $start, $end - $start);

        $this->assertStringContainsString('return $this->handoffExitCode();', $block);
        $this->assertStringNotContainsString('return Command::SUCCESS', $block, '핸드오프가 성공(0)으로 끝나면 스크립트가 재개 명령을 건너뛴다');

        $this->assertStringContainsString(
            'return UpgradeHandoffException::EXIT_CODE;',
            (string) file_get_contents(app_path('Console/Commands/Core/ExecuteUpgradeStepsCommand.php')),
            '단독 core:execute-upgrade-steps 와 같은 종료 코드여야 한다',
        );
    }

    /**
     * 회귀 — 정상 종료는 0, 번들 확장 일괄 업데이트 실패는 1 이다.
     *
     * @scenario case=normal_and_bundled_failure
     *
     * @effects core_update_normal_and_bundled_failure_exit_codes_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_normal_and_bundled_failure_exit_codes_are_unchanged(): void
    {
        $command = $this->commandWithBufferedOutput(CoreUpdateCommand::class);
        $method = new ReflectionMethod($command, 'bundledUpdateExitCode');

        $this->assertSame(Command::SUCCESS, $method->invoke($command, ['success' => 0, 'failed' => 0], '7.2.0'));
        $this->assertSame(Command::SUCCESS, $method->invoke($command, ['success' => 3, 'failed' => 0], '7.2.0'));
        $this->assertSame(Command::FAILURE, $method->invoke($command, ['success' => 2, 'failed' => 1], '7.2.0'));
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
