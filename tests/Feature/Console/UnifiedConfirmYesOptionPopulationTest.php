<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 확인 질문을 쓰는 명령 전체가 `--yes` 를 받고, 거절 갈래가 트레이트의 종료코드를 쓰는지 잠근다.
 *
 * 모집단은 저장소에서 파생한다 — `unifiedConfirm()` 을 부르는 명령 클래스 전부.
 * 새 명령이 확인 질문을 쓰면 자동으로 이 시험의 대상이 된다.
 *
 * @scenario-manifest tests/scenarios/console-unified-confirm.yaml
 */
class UnifiedConfirmYesOptionPopulationTest extends TestCase
{
    /**
     * 확인 질문을 쓰는 명령은 모두 `--yes`(짧게 `-y`) 옵션을 갖는다.
     *
     * @scenario answer=yes_option
     *
     * @effects confirm_commands_expose_yes_option
     *
     * @return void 반환값 없음
     */
    public function test_every_confirming_command_exposes_yes_option(): void
    {
        $commands = $this->confirmingCommands();

        // 모집단이 비어 통과하지 않게 — 조사 시점 13개
        $this->assertGreaterThanOrEqual(13, count($commands));

        $missing = [];
        foreach ($commands as $name => $command) {
            $definition = $command->getDefinition();
            if (! $definition->hasOption('yes') || $definition->getOption('yes')->getShortcut() !== 'y') {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * 확인을 거절해 끝내는 자리는 성공 종료코드를 직접 돌려주지 않고 트레이트에 묻는다.
     *
     * 직접 `SUCCESS` 를 돌려주면 터미널 없는 실행에서 「아무 일도 하지 않은」 것이 성공으로 보인다.
     *
     * @scenario answer=non_interactive
     *
     * @effects confirm_non_interactive_decline_warns_and_fails
     *
     * @return void 반환값 없음
     */
    public function test_decline_branches_use_trait_exit_code(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->confirmingSourceFiles() as $path => $source) {
            // `if (… ! $this->unifiedConfirm(…)) { … return X; }` — 거절 갈래의 첫 return
            if (! preg_match_all('/!\s*\$this->unifiedConfirm\((?:[^;{]|\{[^}]*\})*?\)\s*\)\s*\{(.*?)\n\s*\}/s', $source, $matches)) {
                continue;
            }
            foreach ($matches[1] as $body) {
                if (! preg_match('/return\s+([^;]+);/', $body, $return)) {
                    continue;
                }
                $checked++;
                $value = trim($return[1]);
                if (preg_match('/^(self|static|Command)::SUCCESS$/', $value)) {
                    $offenders[] = basename($path).': return '.$value;
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked);
        $this->assertSame([], $offenders);
    }

    /**
     * 비대화 실행에서 `--yes` 없이 부르면 실패로 끝나고, `--yes` 를 주면 확인을 지나 진행한다.
     *
     * 실제 명령 하나(`settings:migrate-to-json` 과 같은 형태)를 흉내 낸 프로브로 Artisan 경로를 확인한다.
     *
     * @scenario answer=non_interactive
     *
     * @effects confirm_non_interactive_decline_warns_and_fails
     *
     * @return void 반환값 없음
     */
    public function test_artisan_non_interactive_run_fails_without_yes_and_proceeds_with_yes(): void
    {
        $this->app[Kernel::class]->registerCommand(new YesOptionProbeCommand);

        $this->artisan('test:probe-yes --no-interaction')
            ->expectsOutputToContain('--yes')
            ->doesntExpectOutput('진행했습니다')
            ->assertExitCode(Command::FAILURE);

        $this->artisan('test:probe-yes --no-interaction --yes')
            ->expectsOutput('진행했습니다')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * `unifiedConfirm()` 을 부르는 명령 인스턴스를 이름별로 모은다.
     *
     * @return array<string, Command> 명령 이름 => 명령
     */
    private function confirmingCommands(): array
    {
        $classes = [];
        foreach ($this->confirmingSourceFiles() as $path => $source) {
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class)) {
                $classes[] = $ns[1].'\\'.$class[1];
            }
        }

        $found = [];
        foreach ($this->app[Kernel::class]->all() as $name => $command) {
            foreach ($classes as $class) {
                if ($command instanceof $class) {
                    $found[$name] = $command;
                }
            }
            // 확인 질문을 트레이트(Concerns)로 받아 쓰는 명령
            if (! isset($found[$name]) && in_array(HasUnifiedConfirm::class, class_uses_recursive($command), true)
                && $this->usesConfirmThroughConcern($command)) {
                $found[$name] = $command;
            }
        }

        return $found;
    }

    /**
     * 명령이 쓰는 트레이트(자기 것 포함) 중 `unifiedConfirm()` 을 부르는 것이 있는지 본다.
     *
     * @param  Command  $command  명령
     * @return bool 부르면 true
     */
    private function usesConfirmThroughConcern(Command $command): bool
    {
        foreach (class_uses_recursive($command) as $trait) {
            if ($trait === HasUnifiedConfirm::class) {
                continue;
            }
            $file = (new \ReflectionClass($trait))->getFileName();
            if ($file !== false && str_contains((string) file_get_contents($file), '$this->unifiedConfirm(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `app/Console` 아래에서 `unifiedConfirm()` 을 부르는 소스 파일을 모은다(트레이트 정의 파일 제외).
     *
     * @return array<string, string> 경로 => 소스
     */
    private function confirmingSourceFiles(): array
    {
        $files = [];
        foreach ((new Finder)->files()->in(app_path('Console'))->name('*.php') as $file) {
            $source = $file->getContents();
            if (str_contains($source, '$this->unifiedConfirm(') && ! str_ends_with($file->getFilename(), 'HasUnifiedConfirm.php')) {
                $files[$file->getRealPath()] = $source;
            }
        }

        return $files;
    }
}

/**
 * `--yes` 회귀 시험용 프로브 — 확인을 거절하면 트레이트의 종료코드로 끝난다.
 */
class YesOptionProbeCommand extends Command
{
    use HasUnifiedConfirm;

    protected $signature = 'test:probe-yes';

    protected $description = '회귀 테스트용 --yes 프로브';

    /**
     * 확인을 묻고, 거절이면 트레이트가 정한 종료코드로 끝낸다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (! $this->unifiedConfirm('진행할까요?', false)) {
            return $this->confirmAbortExitCode();
        }

        $this->line('진행했습니다');

        return self::SUCCESS;
    }
}
