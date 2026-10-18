<?php

namespace Tests\Unit\Console\Commands\Traits;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use Illuminate\Console\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * HasUnifiedConfirm trait 단위 테스트.
 *
 * Symfony QuestionHelper 를 거치므로 CommandTester 의 setInputs() 로 STDIN 시뮬레이션,
 * --no-interaction 시 default 즉시 반환을 검증한다.
 */
class HasUnifiedConfirmTest extends TestCase
{
    public function test_returns_default_when_non_interactive_default_false(): void
    {
        $tester = $this->makeTester(false);

        $tester->execute([], ['interactive' => false]);

        $this->assertSame('false', trim($tester->getDisplay()));
    }

    public function test_returns_default_when_non_interactive_default_true(): void
    {
        $tester = $this->makeTester(true);

        $tester->execute([], ['interactive' => false]);

        $this->assertSame('true', trim($tester->getDisplay()));
    }

    public function test_resolves_immediately_on_yes_input(): void
    {
        $tester = $this->makeTester(false);
        $tester->setInputs(['yes']);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringEndsWith('true', trim($display));
        $this->assertStringNotContainsString('yes, y, no, n', $display);
    }

    public function test_resolves_immediately_on_no_input(): void
    {
        $tester = $this->makeTester(true);
        $tester->setInputs(['no']);

        $tester->execute([]);

        $this->assertStringEndsWith('false', trim($tester->getDisplay()));
    }

    public function test_resolves_on_y_short_input(): void
    {
        $tester = $this->makeTester(false);
        $tester->setInputs(['y']);

        $tester->execute([]);

        $this->assertStringEndsWith('true', trim($tester->getDisplay()));
    }

    public function test_returns_default_on_empty_input(): void
    {
        $tester = $this->makeTester(true);
        $tester->setInputs(['']);

        $tester->execute([]);

        $this->assertStringEndsWith('true', trim($tester->getDisplay()));
    }

    public function test_loops_on_invalid_input_then_resolves(): void
    {
        $tester = $this->makeTester(false);
        $tester->setInputs(['abc', 'y']);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('yes, y, no, n 중 하나로 입력해 주세요.', $display);
        $this->assertStringEndsWith('true', trim($display));
    }

    public function test_loops_multiple_times_on_invalid_input(): void
    {
        $tester = $this->makeTester(true);
        $tester->setInputs(['abc', 'foo', 'no']);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(2, substr_count($display, 'yes, y, no, n 중 하나로 입력해 주세요.'));
        $this->assertStringEndsWith('false', trim($display));
    }

    /**
     * Symfony QuestionHelper 가 default 값을 자동 표시하는 `[default]` 가
     * 우리가 직접 그린 `[yes]` / `[no]` 옆에 빈 `[]` 로 중복 출력되지 않아야 한다.
     *
     * 회귀 시나리오 (이전): `진행할까요? (yes/no) [no] []:`
     * 정상: `진행할까요? (yes/no) [no]:`
     */
    public function test_prompt_does_not_show_duplicate_empty_default_brackets(): void
    {
        $tester = $this->makeTester(false);
        $tester->setInputs(['no']);

        $tester->execute([]);

        $display = $tester->getDisplay();
        // `[no] []` 또는 `[yes] []` 같은 중복 default 표시가 없어야 함
        $this->assertStringNotContainsString('[no] []', $display);
        $this->assertStringNotContainsString('[yes] []', $display);
        $this->assertStringNotContainsString('[]', $display);
        // 정상 prompt 형식 확인
        $this->assertStringContainsString('진행할까요? (yes/no) [no]:', $display);
    }

    public function test_prompt_does_not_show_duplicate_brackets_when_default_yes(): void
    {
        $tester = $this->makeTester(true);
        $tester->setInputs(['yes']);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('[]', $display);
        $this->assertStringContainsString('진행할까요? (yes/no) [yes]:', $display);
    }

    /**
     * 응답을 받을 수 없는 실행에서는 질문하지 않고 기본값을 돌려준다.
     *
     * Symfony 는 `posix_isatty()` 로 비대화 실행을 자동 감지하는데 Windows PHP 에는
     * posix 확장이 없어 그 분기가 실행되지 않는다. 그래서 `--no-interaction` 없이
     * 에이전트·CI·스케줄러에서 부르면 `isInteractive()` 가 true 로 남아 `ask()` 가
     * 응답을 기다리며 무한 대기한다. 출력도 남지 않아 "명령이 느리다" 로만 보인다.
     */
    public function test_returns_default_without_asking_when_prompt_cannot_be_answered(): void
    {
        $tester = $this->makeTester(true, canPrompt: false);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame('true', trim($display));
        $this->assertStringNotContainsString('진행할까요?', $display);
    }

    /**
     * 기본값이 false 인 파괴적 확인은 응답을 받을 수 없을 때 중단으로 떨어져야 한다.
     */
    public function test_returns_false_default_without_asking_when_prompt_cannot_be_answered(): void
    {
        $tester = $this->makeTester(false, canPrompt: false);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame('false', trim($display));
        $this->assertStringNotContainsString('진행할까요?', $display);
    }

    /**
     * @param  bool  $default  프롬프트 기본값
     * @param  bool|null  $canPrompt  응답 가능 여부 강제 (null 이면 실제 판정 사용)
     */
    private function makeTester(bool $default, ?bool $canPrompt = null): CommandTester
    {
        $command = new class($default, $canPrompt) extends Command
        {
            // 트레이트 메서드는 parent:: 로 부를 수 없어 별칭으로 원 구현을 남긴다
            use HasUnifiedConfirm {
                canPromptForAnswer as private traitCanPromptForAnswer;
            }

            protected $signature = 'test:unified-confirm';

            public function __construct(private bool $defaultValue, private ?bool $canPrompt = null)
            {
                parent::__construct();
            }

            protected function canPromptForAnswer(): bool
            {
                return $this->canPrompt ?? $this->traitCanPromptForAnswer();
            }

            public function handle(): int
            {
                $result = $this->unifiedConfirm('진행할까요?', $this->defaultValue);
                $this->line($result ? 'true' : 'false');

                return self::SUCCESS;
            }
        };

        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}
