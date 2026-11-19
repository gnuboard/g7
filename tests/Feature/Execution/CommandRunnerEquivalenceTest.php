<?php

namespace Tests\Feature\Execution;

use App\Execution\ExecutionMode;
use App\Execution\InProcessCommandRunner;
use App\Execution\SubprocessCommandRunner;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\Concerns\SkipsWhenExtensionTablesAreMissing;
use Tests\TestCase;

/**
 * 두 러너(서브프로세스·in-process)의 등가 계약 테스트.
 *
 * 같은 커맨드·같은 파라미터 표기가 두 러너에서 같은 의미로 실행되는 것을 고정한다 —
 * 한쪽 러너만 고치면 이 테스트가 red 가 된다 (A-0 회귀 보장 3).
 *
 * DB 를 건드리지 않는 안전한 커맨드(list/help)만 사용한다 — 서브프로세스는
 * 실제 `php artisan` 을 스폰하므로 부수효과 있는 커맨드는 여기서 돌리지 않는다.
 *
 * 커버 축: php_binary_source detected_autodetect·configured / runner_strategy subprocess·inprocess
 */
class CommandRunnerEquivalenceTest extends TestCase
{
    use SkipsWhenExtensionTablesAreMissing;

    /**
     * 확장 테이블 판정은 앱 부팅 **전**에 해야 한다 — 부팅이 바로 그 실패 지점이다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        $this->skipWhenExtensionTablesAreMissing();

        parent::setUp();
    }

    /**
     * 파라미터 표기 → CLI 인자 변환 계약 (Artisan::call 규약과 동일 의미).
     *
     * @effects format_parameters_matches_artisan_call_convention
     *
     * @return void 반환값 없음
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
     *
     * @effects inprocess_runner_executes_safe_command
     *
     * @return void 반환값 없음
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
     *
     * @effects both_runners_agree_on_safe_command_exit_status
     *
     * @return void 반환값 없음
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
     * 등가 계약 — 숫자 키 위치 인자(`[0 => 'value']`)가 두 러너 모두에서 인자로 도달한다.
     *
     * 서브프로세스는 argv 로 자연스럽게 전달되지만, `Artisan::call` 은 Symfony ArrayInput 이
     * 숫자 키를 인자 이름으로 저장해 버려 "Not enough arguments (missing: …)" 가 된다 —
     * 인스톨러 태스크(`template:install {identifier}`)가 inprocess 에서 처음 밟은 사각.
     * `help {command_name}` 은 DB 를 건드리지 않는 위치 인자 커맨드다.
     *
     * @scenario runner_strategy=subprocess, runner_strategy=inprocess
     *
     * @effects both_runners_accept_positional_arguments
     *
     * @return void 반환값 없음
     */
    public function test_both_runners_accept_positional_arguments(): void
    {
        $inProcess = (new InProcessCommandRunner)->run('help', ['list']);

        $this->assertSame(0, $inProcess->exitCode, $inProcess->output);
        $this->assertStringContainsString('list', $inProcess->output);

        if (! function_exists('proc_open')) {
            return;
        }

        $subprocess = (new SubprocessCommandRunner)->run('help', ['list']);
        $this->assertSame($inProcess->exitCode, $subprocess->exitCode, $subprocess->output);
    }

    /**
     * 필수 위치 인자 — `help` 의 인자는 선택이라 위 테스트만으로는 증명되지 않는다.
     * 필수 인자를 가진 임시 클로저 커맨드로, 숫자 키 값이 인자 **이름**에 매핑되어 도달하는지 고정한다.
     * (인스톨러 `template:install {identifier}` 가 inprocess 에서 밟은 결함의 직접 재현)
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects inprocess_runner_maps_positional_values_to_required_arguments
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_runner_maps_positional_values_to_required_arguments(): void
    {
        Artisan::command('g7:test-positional {identifier} {--vendor-mode=}', function () {
            $this->line('got='.$this->argument('identifier').' mode='.($this->option('vendor-mode') ?? 'none'));
        });

        $result = (new InProcessCommandRunner)->run('g7:test-positional', ['sirsoft-admin_basic', '--vendor-mode' => 'bundled']);

        $this->assertSame(0, $result->exitCode, $result->output);
        $this->assertStringContainsString('got=sirsoft-admin_basic mode=bundled', $result->output);
    }

    /**
     * 위치 인자를 인자명 키로 넘기는 기존 표기(`['name' => 'value']`)도 계속 성립한다.
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects inprocess_runner_keeps_named_argument_convention
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_runner_keeps_named_argument_convention(): void
    {
        $result = (new InProcessCommandRunner)->run('help', ['command_name' => 'list']);

        $this->assertSame(0, $result->exitCode, $result->output);
    }

    /**
     * 배열 인자(`{items*}`)에는 남는 위치 값이 전부 모인다.
     *
     * 종전 테스트는 전부 **단일 스칼라** 인자만 태워서 `$argument->isArray()` 두 분기와
     * 「정의보다 값이 많을 때」 폴백이 한 번도 평가되지 않았다 — 두 러너 등가 계약이
     * 인스톨러가 지금 쓰는 표기 범위에서만 성립하던 상태다.
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects inprocess_runner_collects_trailing_values_into_array_argument
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_runner_collects_trailing_values_into_an_array_argument(): void
    {
        Artisan::command('g7:test-array-arg {first} {items*}', function () {
            $this->line('first='.$this->argument('first').' items='.implode(',', $this->argument('items')));
        });

        $result = (new InProcessCommandRunner)->run('g7:test-array-arg', ['a', 'b', 'c']);

        $this->assertSame(0, $result->exitCode, $result->output);
        $this->assertStringContainsString('first=a items=b,c', $result->output);
    }

    /**
     * 마지막 인자가 배열이면 정의보다 많은 값도 그 배열에 덧붙는다.
     *
     * 인자가 배열 하나뿐일 때는 위 테스트의 `array_slice` 경로가 아니라 「정의보다 값이 많다」
     * 폴백이 도는 조합이 생긴다 — 두 경로가 같은 결과를 내는지 함께 고정한다.
     *
     * @scenario runner_strategy=inprocess
     *
     * @effects inprocess_runner_appends_overflow_values_to_trailing_array_argument
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_runner_appends_overflow_values_to_a_trailing_array_argument(): void
    {
        Artisan::command('g7:test-array-only {items*}', function () {
            $this->line('items='.implode(',', $this->argument('items')));
        });

        $result = (new InProcessCommandRunner)->run('g7:test-array-only', ['x', 'y', 'z']);

        $this->assertSame(0, $result->exitCode, $result->output);
        $this->assertStringContainsString('items=x,y,z', $result->output);
    }

    /**
     * D35 — 생성자로 주입한 PHP 바이너리 경로가 실제 스폰에 쓰인다.
     *
     * 현재 프로세스의 PHP_BINARY 를 명시 주입해 같은 커맨드가 성공하면, 경로 주입이
     * 설정값을 우회해 스폰까지 도달했다는 뜻이다.
     *
     * @effects subprocess_runner_uses_injected_php_binary
     *
     * @return void 반환값 없음
     */
    public function test_subprocess_runner_uses_injected_php_binary(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 차단 환경 — 서브프로세스 러너는 이 환경의 측정 대상이 아니다 (P0 프로필)');
        }

        // 폴백을 **실패하는 값**으로 몰아 둔다. 종전에는 주입값이 PHP_BINARY 이고
        // 폴백도 PATH 의 `php` 라 둘 다 성공해서, 주입을 통째로 무시하도록 되돌려도 초록이었다 —
        // D35 의 종단 계약(판정이 고른 경로가 실제 스폰에 도달한다)이 잠기지 않았다.
        config(['process.php_binary' => __DIR__.'/no-such-php-binary']);

        $runner = new SubprocessCommandRunner(PHP_BINARY);

        $this->assertSame(PHP_BINARY, $runner->phpBinary());

        $result = $runner->run('list', ['--format' => 'txt']);

        $this->assertSame(0, $result->exitCode, '주입한 경로가 스폰에 도달하지 않으면 폴백(존재하지 않는 경로)으로 실패한다: '.$result->output);
        $this->assertNotSame('', trim($result->output));

        // 반대 방향 — 주입이 없으면 폴백을 탄다(그리고 그 폴백이 실패한다).
        $fallbackRunner = new SubprocessCommandRunner;
        $this->assertNotSame(0, $fallbackRunner->run('list', ['--format' => 'txt'])->exitCode, '주입이 없으면 설정 폴백을 타야 한다');
    }

    /**
     * 등가 계약 — 존재하지 않는 커맨드는 두 러너 모두 비-0 으로 끝난다.
     * (실패를 성공으로 뭉개는 러너가 없어야 한다)
     *
     * @effects both_runners_agree_on_unknown_command_failure
     *
     * @return void 반환값 없음
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

        $runner = new SubprocessCommandRunner;

        // **살아 있는 러너**임을 먼저 고정한다. `assertNotSame(0, ...)` 하나만으로는
        // 러너가 통째로 망가져 프로세스가 뜨지도 않은 경우(어떤 커맨드든 비-0)와 「미존재
        // 커맨드를 정상적으로 거부했다」가 구분되지 않는다 — 앞서 형제 검사에서 닫은 축이다.
        $baseline = $runner->run('list', ['--format' => 'txt']);
        $this->assertSame(
            0,
            $baseline->exitCode,
            "서브프로세스 러너가 알려진 커맨드조차 실행하지 못한다 — 아래 비-0 은 거부가 아니라 고장이다.\n".$baseline->output
        );

        $subprocess = $runner->run('g7:no-such-command-p1');

        $this->assertTrue($inProcessFailed, 'in-process 러너가 미존재 커맨드를 성공 처리했습니다');
        $this->assertNotSame(0, $subprocess->exitCode, '서브프로세스 러너가 미존재 커맨드를 성공 처리했습니다');
        $this->assertNotSame(
            '',
            trim($subprocess->output),
            '거부라면 사유가 실려야 한다 — 출력이 비면 프로세스가 뜨지 못했을 가능성과 구분되지 않는다'
        );
    }
}
