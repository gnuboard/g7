<?php

namespace Tests\Unit\Console\Commands\Traits;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use Illuminate\Console\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * HasUnifiedConfirm 의 `--yes` 옵션과 비대화 거절 처리 단위 테스트.
 *
 * 확인 질문을 쓰는 명령은 터미널 없는 실행(스케줄러·배포 스크립트·`-n`)에서 기본값 「아니오」로
 * 조용히 성공 종료했다. 그래서 「실행했는데 아무 일도 일어나지 않은」 것을 알 수 없었다.
 * `--yes` 로 진행을 명시할 수 있고, 명시하지 않은 비대화 거절은 경고와 실패 종료코드로 드러난다.
 *
 * @scenario-manifest tests/scenarios/console-unified-confirm.yaml
 */
class HasUnifiedConfirmYesOptionTest extends TestCase
{
    /**
     * `--yes` 를 주면 대화형 실행에서도 묻지 않고 진행한다.
     *
     * @scenario answer=yes_option
     *
     * @effects confirm_yes_option_answers_without_prompt
     *
     * @return void 반환값 없음
     */
    public function test_yes_option_answers_true_without_prompt(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['--yes' => true]);

        $this->assertSame('confirm=true', trim($tester->getDisplay()));
        $this->assertSame(Command::SUCCESS, $exit);
    }

    /**
     * 짧은 옵션 `-y` 도 같은 뜻이고, 비대화 실행에서도 진행한다.
     *
     * @scenario answer=yes_option
     *
     * @effects confirm_yes_option_answers_without_prompt
     *
     * @return void 반환값 없음
     */
    public function test_short_yes_option_works_in_non_interactive_run(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['-y' => true], ['interactive' => false]);

        $this->assertSame('confirm=true', trim($tester->getDisplay()));
        $this->assertSame(Command::SUCCESS, $exit);
    }

    /**
     * 비대화 실행에서 `--yes` 없이 기본값 「아니오」로 떨어지면 경고를 내고 실패로 끝난다.
     *
     * @scenario answer=non_interactive
     *
     * @effects confirm_non_interactive_decline_warns_and_fails
     *
     * @return void 반환값 없음
     */
    public function test_non_interactive_decline_warns_and_exits_with_failure(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute([], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('--yes', $display);
        $this->assertStringContainsString('진행할까요?', $display);
        $this->assertStringContainsString('confirm=false', $display);
        $this->assertSame(Command::FAILURE, $exit);
    }

    /**
     * 응답을 받을 수 없는 실행(콘솔 없는 STDIN)도 비대화 거절과 같이 다룬다.
     *
     * @scenario answer=unanswerable
     *
     * @effects confirm_non_interactive_decline_warns_and_fails
     *
     * @return void 반환값 없음
     */
    public function test_unanswerable_decline_warns_and_exits_with_failure(): void
    {
        $tester = $this->makeTester(canPrompt: false);

        $exit = $tester->execute([]);

        $this->assertStringContainsString('--yes', $tester->getDisplay());
        $this->assertSame(Command::FAILURE, $exit);
    }

    /**
     * 사람이 직접 「아니오」라고 답한 것은 실패가 아니다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_interactive_decline_exits_success
     *
     * @return void 반환값 없음
     */
    public function test_interactive_no_exits_with_success(): void
    {
        $tester = $this->makeTester();
        $tester->setInputs(['no']);

        $exit = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('confirm=false', $display);
        $this->assertStringNotContainsString('--yes', $display);
        $this->assertSame(Command::SUCCESS, $exit);
    }

    /**
     * 진행 여부가 아니라 부가 선택을 묻는 질문은 `--yes`·비대화 실행에서 기본값을 그대로 쓴다.
     *
     * `--yes` 가 「검색 색인도 다시 만들까요? [no]」 같은 부가 질문까지 「예」로 바꾸면
     * 운영 중인 사이트에서 원하지 않은 무거운 작업이 따라 돈다.
     *
     * @scenario answer=yes_option
     *
     * @effects confirm_optional_question_keeps_default
     *
     * @return void 반환값 없음
     */
    public function test_optional_question_keeps_default_with_yes_option_and_without_warning(): void
    {
        $withYes = $this->makeTester(optional: true);
        $exit = $withYes->execute(['--yes' => true]);
        $this->assertSame('confirm=false', trim($withYes->getDisplay()));
        $this->assertSame(Command::SUCCESS, $exit);

        $nonInteractive = $this->makeTester(optional: true);
        $exit = $nonInteractive->execute([], ['interactive' => false]);
        $this->assertSame('confirm=false', trim($nonInteractive->getDisplay()));
        $this->assertSame(Command::SUCCESS, $exit);
    }

    /**
     * 선택·입력 질문은 답을 받을 수 없거나 `--yes` 면 기다리지 않고 기본값을 돌려준다.
     *
     * @scenario answer=non_interactive
     *
     * @effects choice_and_ask_return_default_when_unanswerable
     *
     * @return void 반환값 없음
     */
    public function test_choice_and_ask_return_default_without_waiting(): void
    {
        $nonInteractive = $this->makeTester(mode: 'choice');
        $nonInteractive->execute([], ['interactive' => false]);
        $this->assertSame('choice=overwrite ask=fallback', trim($nonInteractive->getDisplay()));

        $unanswerable = $this->makeTester(mode: 'choice', canPrompt: false);
        $unanswerable->execute([]);
        $this->assertSame('choice=overwrite ask=fallback', trim($unanswerable->getDisplay()));

        $withYes = $this->makeTester(mode: 'choice');
        $withYes->execute(['--yes' => true]);
        $this->assertSame('choice=overwrite ask=fallback', trim($withYes->getDisplay()));
    }

    /**
     * 대화형 실행에서는 선택·입력 질문이 지금처럼 답을 받는다.
     *
     * @scenario answer=valid
     *
     * @effects choice_and_ask_return_default_when_unanswerable
     *
     * @return void 반환값 없음
     */
    public function test_choice_and_ask_still_prompt_in_interactive_run(): void
    {
        $tester = $this->makeTester(mode: 'choice');
        // 입력은 ASCII 로 — Windows 콘솔 입력 스트림은 한글을 다른 인코딩으로 읽는다
        $tester->setInputs(['keep', 'typed-value']);

        $tester->execute([]);

        $this->assertStringContainsString('choice=keep ask=typed-value', $tester->getDisplay());
    }

    /**
     * 통합 확인 결과를 출력하고, 거절이면 트레이트가 정한 종료코드로 끝나는 익명 커맨드의 테스터를 만든다.
     *
     * @param  string  $mode  confirm(확인 질문) 또는 choice(선택·입력 질문)
     * @param  bool  $optional  부가 선택 질문 여부
     * @param  bool|null  $canPrompt  응답 가능 여부 강제 (null 이면 실제 판정 사용)
     * @return CommandTester 익명 커맨드를 감싼 CommandTester
     */
    private function makeTester(string $mode = 'confirm', bool $optional = false, ?bool $canPrompt = null): CommandTester
    {
        $command = new class($mode, $optional, $canPrompt) extends Command
        {
            // 트레이트 메서드는 parent:: 로 부를 수 없어 별칭으로 원 구현을 남긴다
            use HasUnifiedConfirm {
                canPromptForAnswer as private traitCanPromptForAnswer;
            }

            protected $signature = 'test:unified-confirm-yes';

            /**
             * 질문 종류와 응답 가능 여부 강제값을 받아 커맨드를 만든다.
             *
             * @param  string  $mode  confirm 또는 choice
             * @param  bool  $optional  부가 선택 질문 여부
             * @param  bool|null  $canPrompt  응답 가능 여부 강제값, null 이면 실제 판정 사용
             */
            public function __construct(private string $mode, private bool $optional, private ?bool $canPrompt = null)
            {
                parent::__construct();
            }

            /**
             * 강제값이 있으면 그 값을, 없으면 트레이트의 실제 판정을 돌려준다.
             *
             * @return bool 응답을 받을 수 있으면 true
             */
            protected function canPromptForAnswer(): bool
            {
                return $this->canPrompt ?? $this->traitCanPromptForAnswer();
            }

            /**
             * 질문을 던지고 결과를 출력한다. 확인을 거절하면 트레이트가 정한 종료코드로 끝난다.
             *
             * @return int 종료 코드
             */
            public function handle(): int
            {
                if ($this->mode === 'choice') {
                    $choice = $this->unifiedChoice('전략을 고르세요', ['overwrite' => '덮어쓰기', 'keep' => '유지'], 'overwrite');
                    $ask = $this->unifiedAsk('대상을 입력하세요', 'fallback');
                    $this->line("choice={$choice} ask={$ask}");

                    return self::SUCCESS;
                }

                $result = $this->unifiedConfirm('진행할까요?', false, $this->optional);
                $this->line('confirm='.($result ? 'true' : 'false'));

                return $result ? self::SUCCESS : $this->confirmAbortExitCode();
            }
        };

        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}
