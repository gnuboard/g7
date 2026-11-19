<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Helpers\PromptCapability;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `--yes` 는 번들 일괄 업데이트의 확인 질문에만 답한다 — 선택·입력·선택 질문은 답하지 않는다 (#888).
 *
 * 종전에는 「답할 수 있는 실행」 판정이 `--yes` 를 보고 참이 되어, 콘솔 없는 `core:update --yes` 가 레이아웃 전략 선택과
 * 확장별 덮어쓰기 입력을 띄워 멈추고, 기본값 「아니오」인 검색 인덱스 재생성을 묻지 않고 켰다.
 *
 * @scenario-manifest tests/scenarios/bundled-prompt-yes-flag.yaml
 */
class BundledPromptYesFlagTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 판정 고정을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        PromptCapability::reset();

        parent::tearDown();
    }

    /**
     * 콘솔 없는 실행 + `--yes` — 확인은 「예」, 선택·입력 질문은 띄우지 않고 기본값(overwrite·재생성 안 함)을 쓴다.
     *
     * @scenario case=no_terminal__yes
     *
     * @effects yes_option_still_answers_the_confirmation, prompts_use_defaults_without_console_even_with_yes, yes_option_does_not_approve_optional_questions
     *
     * @return void 반환값 없음
     */
    public function test_yes_without_console_uses_prompt_defaults(): void
    {
        PromptCapability::fake(false);
        $harness = $this->bundledPromptHarness();

        $this->assertTrue($harness->proceed(), '--yes 는 확인 질문에 「예」로 답한다');
        $this->assertSame('overwrite', $harness->globalStrategy(), '콘솔이 없으면 --yes 여도 레이아웃 전략은 기본값');
        $this->assertSame(
            ['modules:acme-a' => 'overwrite', 'plugins:acme-b' => 'overwrite'],
            $harness->strategies($this->sampleUpdates(), 'overwrite'),
        );
        $this->assertFalse($harness->rebuild(), '--yes 는 검색 인덱스 재생성(선택 질문)을 켜지 않는다');
        $this->assertSame([], $harness->questions, '콘솔이 없으면 질문 함수가 불리면 안 된다');
    }

    /**
     * 콘솔 있는 실행 + `--yes` — 선택 질문(확장별 덮어쓰기 변경)은 「예」로 미리 답하지 않고 그대로 묻는다.
     *
     * @scenario case=interactive__yes
     *
     * @effects yes_option_still_answers_the_confirmation, yes_option_does_not_approve_optional_questions
     *
     * @return void 반환값 없음
     */
    public function test_yes_with_console_still_asks_optional_questions(): void
    {
        PromptCapability::fake(true);
        $harness = $this->bundledPromptHarness();

        $this->assertTrue($harness->proceed(), '--yes 는 확인 질문에 「예」로 답한다');
        $this->assertSame([], $harness->questions, '확인 질문은 --yes 로 묻지 않는다');

        // 빈 답 = 기본값(아니오) — 덮어쓰기 대상 입력(override_ask)으로 넘어가지 않는다
        $strategies = $harness->strategies($this->sampleUpdates(), 'overwrite');

        $this->assertSame(['modules:acme-a' => 'overwrite', 'plugins:acme-b' => 'overwrite'], $strategies);
        $this->assertCount(1, $harness->questions, '선택 질문 하나만 묻는다');
        $this->assertStringContainsString(__('extensions.commands.bundled_update.override_confirm'), $harness->questions[0]);
        $this->assertFalse($harness->rebuild(), '빈 답이면 재생성하지 않는다');
    }

    /**
     * 시험용 업데이트 목록.
     *
     * @return array<string, array<int, array{identifier: string}>> 업데이트 목록
     */
    private function sampleUpdates(): array
    {
        return ['modules' => [['identifier' => 'acme-a']], 'plugins' => [['identifier' => 'acme-b']], 'templates' => []];
    }

    /**
     * `--yes` 를 선언하고 받은 일괄 업데이트 프롬프트 시험용 명령 — 질문 함수가 불리면 질문을 기록한다.
     *
     * @return object 시험용 명령
     */
    private function bundledPromptHarness(): object
    {
        $command = new class extends Command
        {
            use BundledExtensionUpdatePrompt;
            use HasUnifiedConfirm;

            /** @var string 명령 이름 */
            protected $signature = 'fx:bundled-prompt-yes-probe {--yes}';

            /** @var array<int, string> 불린 질문 */
            public array $questions = [];

            /**
             * 실행 본문 — 쓰지 않는다.
             *
             * @return int 종료 코드
             */
            public function handle(): int
            {
                return self::SUCCESS;
            }

            /**
             * 일괄 업데이트 진행 확인(확인 질문)에 답합니다.
             *
             * @return bool 진행하면 true
             */
            public function proceed(): bool
            {
                return $this->unifiedConfirm(__('extensions.commands.bundled_update.confirm_proceed'), true);
            }

            /**
             * 전역 레이아웃 전략을 정합니다.
             *
             * @return string 전략
             */
            public function globalStrategy(): string
            {
                return $this->askGlobalLayoutStrategy(false);
            }

            /**
             * 확장별 전략을 정합니다.
             *
             * @param  array<string, array<int, array{identifier: string}>>  $updates  업데이트 목록
             * @param  string  $global  전역 전략
             * @return array<string, string> 전략
             */
            public function strategies(array $updates, string $global): array
            {
                return $this->collectPerExtensionStrategies($updates, $global, false);
            }

            /**
             * 검색 인덱스 재생성 여부를 정합니다.
             *
             * @return bool 재생성하면 true
             */
            public function rebuild(): bool
            {
                return $this->askRebuildSearchIndex(false);
            }

            /**
             * 질문 — 불리면 기록하고 빈 답(기본값)을 돌려준다.
             *
             * @param  string  $question  질문
             * @param  string|null  $default  기본값
             * @return string 빈 답
             */
            public function ask($question, $default = null)
            {
                $this->questions[] = (string) $question;

                return '';
            }

            /**
             * 선택 — 불리면 기록하고 기본값을 돌려준다.
             *
             * @param  string  $question  질문
             * @param  array<int|string, string>  $choices  선택지
             * @param  string|int|null  $default  기본값
             * @param  int|null  $attempts  시도 수
             * @param  bool  $multiple  복수 선택
             * @return string 기본값
             */
            public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false)
            {
                $this->questions[] = (string) $question;

                return (string) $default;
            }
        };
        $command->setLaravel($this->app);
        $command->run(new ArrayInput(['--yes' => true]), new BufferedOutput);

        return $command;
    }
}
