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
    protected function setUp(): void
    {
        parent::setUp();

        // 익명 Command 를 Artisan 에 등록한다 (Kernel::registerCommand 동등).
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand(new ProbeCommand);
    }

    public function test_no_interaction_returns_default_no(): void
    {
        $this->artisan('test:probe-confirm --default=no --no-interaction')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }

    public function test_no_interaction_returns_default_yes(): void
    {
        $this->artisan('test:probe-confirm --default=yes --no-interaction')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    public function test_yes_input_resolves_immediately(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'yes')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    public function test_no_input_resolves_immediately_when_default_yes(): void
    {
        $this->artisan('test:probe-confirm --default=yes')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'no')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }

    public function test_short_y_resolves_to_yes(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'y')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    public function test_uppercase_yes_resolves_to_yes(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'YES')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    public function test_empty_input_uses_default_yes(): void
    {
        $this->artisan('test:probe-confirm --default=yes')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', '')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    public function test_empty_input_uses_default_no(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', '')
            ->expectsOutput('result=no')
            ->assertExitCode(0);
    }

    public function test_invalid_input_loops_until_valid(): void
    {
        $this->artisan('test:probe-confirm --default=no')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'yell_no')
            ->expectsOutput('  yes, y, no, n 중 하나로 입력해 주세요.')
            ->expectsQuestion('진행할까요? (yes/no) [no]', 'y')
            ->expectsOutput('result=yes')
            ->assertExitCode(0);
    }

    public function test_multiple_invalid_inputs_loop_then_resolve(): void
    {
        $this->artisan('test:probe-confirm --default=yes')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'foo')
            ->expectsOutput('  yes, y, no, n 중 하나로 입력해 주세요.')
            ->expectsQuestion('진행할까요? (yes/no) [yes]', 'bar')
            ->expectsOutput('  yes, y, no, n 중 하나로 입력해 주세요.')
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

    public function handle(): int
    {
        $default = $this->option('default') === 'yes';
        $result = $this->unifiedConfirm('진행할까요?', $default);

        $this->line('result='.($result ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
