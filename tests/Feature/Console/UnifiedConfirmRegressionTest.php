<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use Illuminate\Console\Command;
use Tests\TestCase;

/**
 * HasUnifiedConfirm trait 통합 회귀 테스트.
 *
 * 9개 매니저 커맨드(core/module/plugin/template uninstall·update + settings:migrate-to-json)에
 * 적용된 신규 confirm 동작 — empty 입력 → default, 유효 입력 즉시 결정,
 * 잘못된 입력 시 안내 + 재질문, --no-interaction 시 default — 가
 * Laravel Artisan 테스트 환경에서 그대로 동작하는지를 한 곳에서 검증한다.
 *
 * 개별 매니저 커맨드 통합 테스트(ModuleArtisanCommandsTest 등)는 정상 흐름
 * (yes/no 답변)만 다룬다. 본 테스트는 신규 확장된 입력 케이스(empty/invalid/loop)와
 * --no-interaction 동작을 트레이트 단위에서 광범위하게 검증한다.
 */
class UnifiedConfirmRegressionTest extends TestCase
{
    /**
     * 확인 결과를 그대로 출력하는 프로브 커맨드를 Artisan 에 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 익명 Command 를 Artisan 에 등록한다 (Kernel::registerCommand 동등).
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand(new ProbeCommand);
    }

    /**
     * --no-interaction 실행이면 묻지 않고 기본값 no 를 돌려주는지 검증한다.
     *
     * @scenario answer=non_interactive
     *
     * @effects confirm_returns_default_without_prompt_when_non_interactive
     *
     * @return void 반환값 없음
     */
    public function test_no_interaction_returns_default_no(): void
    {
        $this->artisan('test:probe-confirm --default=no --no-interaction')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }

    /**
     * --no-interaction 실행이면 묻지 않고 기본값 yes 를 돌려주는지 검증한다.
     *
     * @scenario answer=non_interactive
     *
     * @effects confirm_returns_default_without_prompt_when_non_interactive
     *
     * @return void 반환값 없음
     */
    public function test_no_interaction_returns_default_yes(): void
    {
        $this->artisan('test:probe-confirm --default=yes --no-interaction')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    /**
     * yes 답변은 한 번에 yes 로 결정되는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_valid_answer_resolves_immediately
     *
     * @return void 반환값 없음
     */
    public function test_yes_input_resolves_immediately(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'yes')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    /**
     * 기본값이 yes 여도 no 답변은 한 번에 no 로 결정되는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_valid_answer_resolves_immediately
     *
     * @return void 반환값 없음
     */
    public function test_no_input_resolves_immediately_when_default_yes(): void
    {
        $this->artisan('test:probe-confirm --default=yes')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'no')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }

    /**
     * 짧은 답 y 가 yes 로 해석되는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_valid_answer_resolves_immediately
     *
     * @return void 반환값 없음
     */
    public function test_short_y_resolves_to_yes(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'y')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    /**
     * 대문자 YES 가 yes 로 해석되는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_valid_answer_resolves_immediately
     *
     * @return void 반환값 없음
     */
    public function test_uppercase_yes_resolves_to_yes(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'YES')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    /**
     * 빈 답변이면 기본값 yes 를 쓰는지 검증한다.
     *
     * @scenario answer=empty
     *
     * @effects confirm_empty_answer_uses_default
     *
     * @return void 반환값 없음
     */
    public function test_empty_input_uses_default_yes(): void
    {
        $this->artisan('test:probe-confirm --default=yes')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', '')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    /**
     * 빈 답변이면 기본값 no 를 쓰는지 검증한다.
     *
     * @scenario answer=empty
     *
     * @effects confirm_empty_answer_uses_default
     *
     * @return void 반환값 없음
     */
    public function test_empty_input_uses_default_no(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', '')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }

    /**
     * 잘못된 답변이면 안내를 보이고 다시 물어 유효한 답에서 결정되는지 검증한다.
     *
     * @scenario answer=invalid_then_valid
     *
     * @effects confirm_invalid_answer_shows_notice_and_reasks
     *
     * @return void 반환값 없음
     */
    public function test_invalid_input_loops_until_valid(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'yell_no')
            ->expectsOutput('  '.__('common.console.console_confirm.invalid_answer'))
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'y')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    /**
     * 잘못된 답변이 여러 번 이어져도 그때마다 안내 후 재질문하고 마지막 유효한 답으로 결정되는지 검증한다.
     *
     * @scenario answer=invalid_then_valid
     *
     * @effects confirm_invalid_answer_shows_notice_and_reasks
     *
     * @return void 반환값 없음
     */
    public function test_multiple_invalid_inputs_loop_then_resolve(): void
    {
        $this->artisan('test:probe-confirm --default=yes')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'foo')
            ->expectsOutput('  '.__('common.console.console_confirm.invalid_answer'))
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'bar')
            ->expectsOutput('  '.__('common.console.console_confirm.invalid_answer'))
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'no')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }
}

/**
 * 테스트 전용 Probe 커맨드 — unifiedConfirm 결과를 stdout 으로 그대로 출력한다.
 */
class ProbeCommand extends Command
{
    use HasUnifiedConfirm;

    protected $signature = 'test:probe-confirm
        {--default=no : 기본값 (yes|no)}';

    protected $description = '회귀 테스트용 confirm 프로브';

    /**
     * --default 옵션으로 기본값을 정해 통합 확인을 묻고 결과를 result=yes 또는 result=no 로 출력한다.
     *
     * @return int 항상 성공 종료 코드
     */
    public function handle(): int
    {
        $default = $this->option('default') === 'yes';
        $result = $this->unifiedConfirm('진행할까요?', $default);

        $this->line('result='.($result ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
