<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt;
use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Helpers\PromptCapability;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 답을 받을 수 없는 실행(콘솔 없음)에서 질문을 띄우지 않는다 — 확인 외의 질문(선택·입력·비밀값)도 같은 판정을 쓴다 (#888).
 *
 * Windows PHP 처럼 비대화 감지가 없는 환경에서는 콘솔이 없어도 `isInteractive()` 가 참으로 남아 `choice()`·`ask()`·`secret()` 이
 * 오지 않을 응답을 무한히 기다렸다. 판정은 `HasUnifiedConfirm::canAnswerConfirmation()` 하나이고, 테스트는 단위 테스트에서 늘
 * 참인 판정을 `PromptCapability::fake(false)` 로 고정해 콘솔 없는 갈래를 잰다.
 *
 * @scenario-manifest tests/scenarios/extension-lifecycle-noninteractive-confirm.yaml
 */
class PromptCapabilityGuardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 콘솔 없는 실행으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PromptCapability::fake(false);
    }

    /**
     * 판정 고정을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        PromptCapability::reset();
        putenv('G7_RESET_PASSWORD');
        putenv('G7_SETTING_VALUE');

        parent::tearDown();
    }

    /**
     * 일괄 업데이트 프롬프트 — 확장별 전략·검색 인덱스 재생성을 묻지 않고 기본값(전역 전략·재생성 안 함)을 쓴다.
     *
     * @scenario input=no_terminal, force_option=absent
     *
     * @effects unanswerable_prompts_use_defaults_without_asking
     *
     * @return void 반환값 없음
     */
    public function test_bundled_update_prompt_does_not_ask_without_console(): void
    {
        $harness = $this->bundledPromptHarness();

        $strategies = $harness->strategies(['modules' => [['identifier' => 'acme-a']], 'plugins' => [['identifier' => 'acme-b']], 'templates' => []], 'overwrite');

        $this->assertSame(['modules:acme-a' => 'overwrite', 'plugins:acme-b' => 'overwrite'], $strategies);
        $this->assertFalse($harness->rebuild());
        $this->assertSame(0, $harness->asked, '질문 함수가 불리면 안 된다');
    }

    /**
     * 비밀번호 재설정 — 비밀번호 출처 없이 콘솔 없는 실행이면 비밀값을 묻지 않고 실패한다.
     *
     * @scenario input=no_terminal, force_option=absent
     *
     * @effects unanswerable_prompts_use_defaults_without_asking
     *
     * @return void 반환값 없음
     */
    public function test_reset_password_does_not_ask_secret_without_console(): void
    {
        $user = User::factory()->create(['email' => 'prompt-'.bin2hex(random_bytes(4)).'@example.test']);

        $this->artisan('user:reset-password', ['email' => $user->email])
            ->expectsOutputToContain(__('user.commands.reset_password.no_source'))
            ->assertExitCode(1);
    }

    /**
     * 설정 지정 — 민감 키에 값 없이 콘솔 없는 실행이면 비밀값을 묻지 않고 「값을 주세요」로 실패한다.
     *
     * @scenario input=no_terminal, force_option=absent
     *
     * @effects unanswerable_prompts_use_defaults_without_asking
     *
     * @return void 반환값 없음
     */
    public function test_settings_set_does_not_ask_secret_without_console(): void
    {
        $this->artisan('settings:set', ['key' => 'drivers.s3_secret_key'])
            ->expectsOutputToContain(__('settings.console.cli.value_missing'))
            ->assertExitCode(1);
    }

    /**
     * 일괄 업데이트 프롬프트 트레이트를 쓰는 시험용 명령 — 질문 함수가 불리면 센다.
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
            protected $signature = 'fx:bundled-prompt-probe';

            /** @var int 질문 함수 호출 수 */
            public int $asked = 0;

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
             * 질문 — 불리면 센다.
             *
             * @param  string  $question  질문
             * @param  string|null  $default  기본값
             * @return string 빈 답
             */
            public function ask($question, $default = null)
            {
                $this->asked++;

                return '';
            }

            /**
             * 선택 — 불리면 센다.
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
                $this->asked++;

                return (string) $default;
            }
        };
        $command->setLaravel($this->app);
        $command->run(new ArrayInput([]), new BufferedOutput);

        return $command;
    }
}
