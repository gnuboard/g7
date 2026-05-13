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

    private function makeTester(bool $default): CommandTester
    {
        $command = new class($default) extends Command
        {
            use HasUnifiedConfirm;

            protected $signature = 'test:unified-confirm';

            public function __construct(private bool $defaultValue)
            {
                parent::__construct();
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
