<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Analytics\AnonymizeCommand;
use App\Console\Commands\Analytics\PurgeCommand;
use App\Console\Commands\Analytics\PurgeExtensionCommand;
use App\Console\Commands\Hotfix\RollbackStaleFilesCommand;
use App\Console\Commands\MigrateSettingsToJsonCommand;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Enums\ExtensionStatus;
use App\Services\Analytics\AnonymizeService;
use App\Services\Analytics\ExtensionPurgeService;
use App\Services\Analytics\PurgeService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확인 질문에 답할 수 없는 되돌릴 수 없는 운영 명령 실행이 성공으로 끝나지 않는지 잰다.
 *
 * `analytics:anonymize`, `analytics:purge`, `analytics:purge-extension`, `hotfix:rollback-stale-files --prune`,
 * `settings:migrate-to-json` 은 작업 전에 확인을 묻는다. cron, CI, 원격 명령, `--no-interaction` 은 답할 수 없어
 * 종전에는 기본값 「아니오」로 「취소」 한 줄을 내고 종료 코드 0 으로 끝났다. 지금은 작업하지 않은 사유와 안내를
 * 출력하고 종료 코드 1 로 끝난다. 확인 생략 옵션(`--force`)이 있는 명령은 그 옵션을 안내하고, 없는 명령은 `--yes`
 * (확인만 생략, 7.1.6 부터)를 안내한다. `analytics:purge` 두 명령의 `--confirm` 은 필수 동의 표식일 뿐 비대화형
 * 동의가 아니다. 대화형에서 운영자가 직접 「아니오」를 답한 취소는 종전처럼 0 이다.
 *
 * 분석 서비스는 목으로 바꾸고, 파일을 지우거나 쓰는 두 명령은 임시 폴더의 표식 파일로 작업 여부를 잰다.
 */
class IrreversibleCommandNonInteractiveConfirmTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 확인을 통과했을 때 실제 작업 대신 목이 던지는 표식 문구.
     */
    private const REACHED_WORK = 'fx-test: reached the work step after confirmation';

    /**
     * `--force` 가 없어 `--yes` 만을 확인 생략 수단으로 안내하는 명령(되돌릴 수 없는 삭제·정리).
     */
    private const YES_ONLY_COMMANDS = ['analytics:purge', 'analytics:purge-extension', 'hotfix:rollback-stale-files'];

    /**
     * 임시 폴더(정리 대상 표식, 설정 파일)와 원래 storage 경로.
     */
    private ?string $tempRoot = null;

    private string $originalStoragePath;

    /**
     * 원래 storage 경로를 기억합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->originalStoragePath = storage_path();
    }

    /**
     * storage 경로를 되돌리고 임시 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);

        if ($this->tempRoot !== null && File::isDirectory($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * 진행 확인을 묻는 다섯 명령.
     *
     * @return array<string, array{0: string}> 명령 이름
     */
    public static function commandProvider(): array
    {
        return [
            'analytics:anonymize' => ['analytics:anonymize'],
            'analytics:purge' => ['analytics:purge'],
            'analytics:purge-extension' => ['analytics:purge-extension'],
            'hotfix:rollback-stale-files' => ['hotfix:rollback-stale-files'],
            'settings:migrate-to-json' => ['settings:migrate-to-json'],
        ];
    }

    /**
     * 확인 생략 옵션(`--force`)이 있는 명령.
     *
     * @return array<string, array{0: string}> 명령 이름
     */
    public static function skipOptionProvider(): array
    {
        return [
            'analytics:anonymize' => ['analytics:anonymize'],
            'settings:migrate-to-json' => ['settings:migrate-to-json'],
        ];
    }

    /**
     * `--no-interaction` 실행은 작업하지 않은 사유와 안내를 출력하고 실패로 끝나며 작업을 시작하지 않는다.
     * 삭제 명령은 `--confirm` 을 붙여도 비대화형 동의로 보지 않는다.
     *
     * @scenario input=no_interaction_option
     *
     * @effects unanswerable_confirmation_fails_with_reason_and_guidance, work_does_not_start_without_confirmation, purge_confirm_option_is_not_noninteractive_consent
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_no_interaction_run_fails_with_reason(string $command): void
    {
        $assertUntouched = $this->prepare($command, workExpected: false);

        $this->artisan($command, $this->arguments($command, ['--no-interaction' => true]))
            ->expectsOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain($this->unavailableHint($command))
            ->doesntExpectOutputToContain($this->cancelledMessage($command))
            ->assertExitCode(Command::FAILURE)
            ->run();

        $assertUntouched();
        $this->assertGuidanceKeys($command);
    }

    /**
     * 터미널 없이 부른 실행(입력이 콘솔이 아님)도 같은 규칙을 따른다 — `--no-interaction` 을 붙이지 않은 원격 명령.
     *
     * @scenario input=no_terminal
     *
     * @effects unanswerable_confirmation_fails_with_reason_and_guidance, work_does_not_start_without_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_run_without_terminal_fails_with_reason(string $command): void
    {
        $assertUntouched = $this->prepare($command, workExpected: false);
        $this->app[Kernel::class]->registerCommand($this->commandWithoutTerminal($command));

        $this->artisan($command, $this->arguments($command))
            ->expectsOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain($this->unavailableHint($command))
            ->doesntExpectOutputToContain($this->cancelledMessage($command))
            ->assertExitCode(Command::FAILURE)
            ->run();

        $assertUntouched();
        $this->assertGuidanceKeys($command);
    }

    /**
     * 확인 생략 옵션(`--force`)이 있는 명령은 비대화형에서도 그 옵션으로 확인을 건너뛰고 작업 단계에 닿는다.
     *
     * @scenario input=skip_option
     *
     * @effects skip_option_passes_the_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('skipOptionProvider')]
    public function test_skip_option_passes_the_confirmation(string $command): void
    {
        $this->prepare($command, workExpected: true);

        try {
            $this->artisan($command, $this->arguments($command, ['--no-interaction' => true, '--force' => true]))
                ->doesntExpectOutputToContain($this->unavailableMessage($command))
                ->run();
            $this->fail('확인을 통과해 작업 단계에 닿아야 한다');
        } catch (\RuntimeException $e) {
            // 익명화: 목이 던진 표식. 설정 이전: 확인 뒤 첫 작업(SystemConfig 조회)이 시험 DB 에 없는 표에 닿은 것.
            $reached = $e->getMessage() === self::REACHED_WORK
                || ($e instanceof QueryException && str_contains($e->getMessage(), 'system_configs'));
            $this->assertTrue($reached, '확인 뒤 작업 단계의 예외여야 한다: '.$e->getMessage());
        }
    }

    /**
     * `--yes`(확인만 생략)는 다섯 명령 모두 비대화형에서 확인을 건너뛰고 작업 단계에 닿는다 — 7.1.6 에서 이 옵션을 붙인
     * 스크립트가 그대로 돈다. 되돌릴 수 없는 삭제(`analytics:purge` 두 명령, `hotfix:rollback-stale-files --prune`)도
     * `--yes` 를 비대화형 동의로 받는다(7.1.6 과 같은 형태). 삭제 명령의 `--confirm` 만으로는 여전히 동의가 아니다.
     *
     * @scenario input=yes_option
     *
     * @effects yes_option_passes_the_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_yes_option_passes_the_confirmation(string $command): void
    {
        $this->prepare($command, workExpected: true);

        if ($command === 'hotfix:rollback-stale-files') {
            // 정리 명령은 목이 없다 — 확인을 통과하면 정리 결과 요약을 출력한다(표식은 보호 경로 storage 아래라 지워지지 않는다).
            // 정리 기록 파일이 실제 storage/logs 에 남지 않게 storage 를 임시 폴더로 돌린다(tearDown 이 되돌리고 지운다).
            app()->useStoragePath($this->tempRoot);
            $donePrefix = strstr(__('common.console.hotfix_rollback_stale_files.done', ['files' => "\0", 'dirs' => "\0", 'protected' => "\0", 'symlinks' => "\0", 'failed' => "\0"]), "\0", true);

            $this->artisan($command, $this->arguments($command, ['--no-interaction' => true, '--yes' => true]))
                ->expectsOutputToContain($donePrefix)
                ->doesntExpectOutputToContain($this->unavailableMessage($command))
                ->doesntExpectOutputToContain($this->cancelledMessage($command))
                ->assertExitCode(Command::SUCCESS)
                ->run();

            return;
        }

        try {
            $this->artisan($command, $this->arguments($command, ['--no-interaction' => true, '--yes' => true]))
                ->doesntExpectOutputToContain($this->unavailableMessage($command))
                ->run();
            $this->fail('확인을 통과해 작업 단계에 닿아야 한다');
        } catch (\RuntimeException $e) {
            $reached = $e->getMessage() === self::REACHED_WORK
                || ($e instanceof QueryException && str_contains($e->getMessage(), 'system_configs'));
            $this->assertTrue($reached, '확인 뒤 작업 단계의 예외여야 한다: '.$e->getMessage());
        }
    }

    /**
     * 대화형에서 운영자가 직접 「아니오」를 답한 취소는 종전처럼 취소 문구와 종료 코드 0 이고 사유 안내를 내지 않는다.
     *
     * @scenario input=interactive_no_answer
     *
     * @effects interactive_no_answer_keeps_success_exit_code, work_does_not_start_without_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_interactive_no_answer_keeps_success_exit_code(string $command): void
    {
        $assertUntouched = $this->prepare($command, workExpected: false);

        $this->artisan($command, $this->arguments($command))
            ->expectsQuestion($this->confirmQuestion($command).' (yes/no) [no]', 'no')
            ->expectsOutput($this->cancelledMessage($command))
            ->doesntExpectOutputToContain($this->unavailableMessage($command))
            ->assertExitCode(Command::SUCCESS)
            ->run();

        $assertUntouched();
    }

    /**
     * 명령이 확인 단계까지 가도록 준비하고, 작업이 일어나지 않았음을 확인하는 단언을 돌려줍니다.
     *
     * @param  string  $command  명령 이름
     * @param  bool  $workExpected  확인을 통과해 작업 단계에 닿아야 하면 true
     * @return \Closure(): void 작업 미진행 단언
     */
    private function prepare(string $command, bool $workExpected): \Closure
    {
        return match ($command) {
            'analytics:anonymize' => $this->mockService(AnonymizeService::class, 'anonymize', $workExpected),
            'analytics:purge' => $this->mockService(PurgeService::class, 'purgeAll', $workExpected),
            'analytics:purge-extension' => $this->prepareExtensionPurge($workExpected),
            'hotfix:rollback-stale-files' => $this->prepareStaleFile(),
            'settings:migrate-to-json' => $this->prepareExistingSettingsFile(),
        };
    }

    /**
     * 분석 서비스 목을 컨테이너에 넣습니다.
     *
     * @param  class-string  $class  서비스 클래스
     * @param  string  $method  작업 메서드
     * @param  bool  $workExpected  작업 메서드가 한 번 불려야 하면 true, 한 번도 불리면 안 되면 false
     * @return \Closure(): void 작업 미진행 단언 (목 기대가 검증하므로 비어 있음)
     */
    private function mockService(string $class, string $method, bool $workExpected): \Closure
    {
        $service = Mockery::mock($class);

        if ($workExpected) {
            $service->shouldReceive($method)->once()->andThrow(new \RuntimeException(self::REACHED_WORK));
        } else {
            $service->shouldNotReceive($method);
        }

        $this->app->instance($class, $service);

        return function (): void {};
    }

    /**
     * 확장 통계 정리 대상을 「설치됨, 꺼짐」 모듈로 두고 정리 서비스 목을 넣습니다.
     *
     * @param  bool  $workExpected  정리 메서드가 한 번 불려야 하면 true
     * @return \Closure(): void 작업 미진행 단언
     */
    private function prepareExtensionPurge(bool $workExpected): \Closure
    {
        $modules = Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing();
        $modules->shouldReceive('getModuleInfo')->andReturn(['is_installed' => true, 'status' => ExtensionStatus::Inactive->value]);
        $this->app->instance(ModuleManagerInterface::class, $modules);

        return $this->mockService(ExtensionPurgeService::class, 'purge', $workExpected);
    }

    /**
     * 정리 대상이 될 표식 파일과 그 파일을 「신 버전 신규 파일」로 적은 백업 manifest 를 임시 폴더에 만듭니다.
     *
     * 표식은 storage 아래 임시 폴더에 두어 실제 소스 트리를 건드리지 않는다.
     *
     * @return \Closure(): void 표식 파일이 남아 있는지 단언
     */
    private function prepareStaleFile(): \Closure
    {
        $this->tempRoot = storage_path('app/test_fx_hotfix_'.uniqid());
        $backup = $this->tempRoot.DIRECTORY_SEPARATOR.'backup';
        $marker = $this->tempRoot.DIRECTORY_SEPARATOR.'active'.DIRECTORY_SEPARATOR.'StaleMarker.php';
        File::ensureDirectoryExists($backup);
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, '<?php // stale marker');

        $relative = ltrim(str_replace('\\', '/', substr($marker, strlen(base_path()))), '/');
        File::put($backup.DIRECTORY_SEPARATOR.'_new_files_manifest.json', json_encode([
            'version' => 1,
            'created_at' => date('c'),
            'from_version' => '7.0.0-beta.5',
            'to_version' => '7.0.0-beta.6',
            'new_files' => [$relative],
            'new_dirs' => [],
        ]));

        return function () use ($marker): void {
            $this->assertFileExists($marker, '확인을 받지 못했으면 정리 대상 파일을 지우지 않는다');
        };
    }

    /**
     * 기존 JSON 설정 파일이 있는 임시 storage 를 쓰고, 이전 원본 표(`system_configs`)가 있다고 응답하게 합니다.
     *
     * 시험 DB 에는 이 옛 표가 없어, 없으면 명령이 확인 전에 기본값 생성 경로로 빠진다. 표 존재 판정만 바꾸고
     * 나머지 스키마 조회는 그대로 둔다.
     *
     * @return \Closure(): void 기존 설정 파일이 그대로인지 단언
     */
    private function prepareExistingSettingsFile(): \Closure
    {
        // 설정 파일은 설정 디스크(시험에서는 페이크)에 둔다 — 명령이 그 디스크로 기존 설정 파일을 판정한다.
        $this->tempRoot = storage_path('test_fx_migrate_'.uniqid());
        File::ensureDirectoryExists($this->tempRoot);
        Storage::disk('settings')->put('general.json', '{"marker":"keep"}');
        $general = Storage::disk('settings')->path('general.json');

        $real = Schema::getFacadeRoot();
        $schema = Mockery::mock(get_class($real), [DB::connection()])->makePartial();
        $schema->shouldReceive('hasTable')->andReturnUsing(
            fn (string $table): bool => $table === 'system_configs' ? true : $real->hasTable($table)
        );
        Schema::swap($schema);

        return function () use ($general): void {
            $this->assertSame('{"marker":"keep"}', File::get($general), '확인을 받지 못했으면 기존 설정 파일을 덮어쓰지 않는다');
        };
    }

    /**
     * 명령에 넘길 인자를 만듭니다.
     *
     * @param  string  $command  명령 이름
     * @param  array<string, mixed>  $extra  추가 옵션
     * @return array<string, mixed> 인자와 옵션
     */
    private function arguments(string $command, array $extra = []): array
    {
        $arguments = match ($command) {
            'analytics:anonymize' => ['--mask-octets' => 1],
            'analytics:purge' => ['--confirm' => true],
            'analytics:purge-extension' => ['type' => 'module', 'identifier' => 'acme-gone', '--confirm' => true],
            'hotfix:rollback-stale-files' => ['--backup' => $this->tempRoot.DIRECTORY_SEPARATOR.'backup', '--prune' => true],
            'settings:migrate-to-json' => [],
        };

        return $arguments + $extra;
    }

    /**
     * 입력이 콘솔이 아닌 실행을 흉내 내는 명령 인스턴스를 만듭니다.
     *
     * 테스트 실행에서는 `canPromptForAnswer()` 가 늘 true 라(테스트가 입력을 주입하므로) 그 판정만 바꾼다.
     *
     * @param  string  $command  명령 이름
     * @return Command 판정을 바꾼 명령
     */
    private function commandWithoutTerminal(string $command): Command
    {
        return match ($command) {
            'analytics:anonymize' => new class extends AnonymizeCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'analytics:purge' => new class extends PurgeCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'analytics:purge-extension' => new class extends PurgeExtensionCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'hotfix:rollback-stale-files' => new class extends RollbackStaleFilesCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'settings:migrate-to-json' => new class extends MigrateSettingsToJsonCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
        };
    }

    /**
     * 명령의 번역 키 접두사와 치환값.
     *
     * @param  string  $command  명령 이름
     * @return array{0: string, 1: array<string, string>} [키 접두사, 치환값]
     */
    private function lang(string $command): array
    {
        return match ($command) {
            'analytics:anonymize' => ['analytics.console.anonymize', []],
            'analytics:purge' => ['analytics.console.purge', []],
            'analytics:purge-extension' => ['analytics.console.purge_extension', ['type' => 'module', 'identifier' => 'acme-gone']],
            'hotfix:rollback-stale-files' => ['common.console.hotfix_rollback_stale_files', []],
            'settings:migrate-to-json' => ['settings.console.migrate_to_json', []],
        };
    }

    /**
     * 작업하지 않은 사유 문구.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 문구
     */
    private function unavailableMessage(string $command): string
    {
        [$prefix, $replace] = $this->lang($command);

        return __("{$prefix}.confirm_unavailable", $replace);
    }

    /**
     * 확인 생략 옵션 또는 터미널 확인 안내 문구.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 문구
     */
    private function unavailableHint(string $command): string
    {
        [$prefix, $replace] = $this->lang($command);

        return __("{$prefix}.confirm_unavailable_hint", $replace);
    }

    /**
     * 진행 확인 질문.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 질문
     */
    private function confirmQuestion(string $command): string
    {
        [$prefix, $replace] = $this->lang($command);

        return match ($command) {
            'hotfix:rollback-stale-files' => __("{$prefix}.confirm_prune"),
            'settings:migrate-to-json' => __("{$prefix}.confirm_overwrite"),
            default => __("{$prefix}.confirm", $replace),
        };
    }

    /**
     * 대화형 「아니오」의 취소 문구.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 문구
     */
    private function cancelledMessage(string $command): string
    {
        [$prefix] = $this->lang($command);

        return __("{$prefix}.cancelled");
    }

    /**
     * 사유와 안내의 번역 키가 실제로 있고, 안내가 명령의 확인 생략 수단을 사실대로 알리는지 확인합니다.
     *
     * 키가 없으면 키 문자열 자체가 출력과 맞는 일을 막는다. `--force` 가 있는 명령은 그 옵션을, 삭제·정리 명령은
     * `--yes` 를 담아야 한다 — 7.2.0 초기의 「확인 없이 지우는 방법은 제공하지 않는다」는 7.1.6 과 같은 `--yes` 수용으로 바뀌었다.
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    private function assertGuidanceKeys(string $command): void
    {
        [$prefix] = $this->lang($command);

        foreach (['confirm_unavailable', 'confirm_unavailable_hint'] as $suffix) {
            $this->assertTrue(trans()->has("{$prefix}.{$suffix}"), "번역 키 {$prefix}.{$suffix} 가 있어야 한다");
        }

        $hint = $this->unavailableHint($command);

        if (in_array($command, ['analytics:anonymize', 'settings:migrate-to-json'], true)) {
            $this->assertStringContainsString('--force', $hint, '확인 생략 옵션이 있으면 그 옵션을 안내한다');
        } else {
            $this->assertStringNotContainsString('--force', $hint, '확인 생략 수단이 없는 명령은 --force 를 안내하지 않는다');
        }

        if (in_array($command, self::YES_ONLY_COMMANDS, true)) {
            $this->assertStringContainsString('--yes', $hint, '확인 생략 수단 --yes 를 안내한다');
            $this->assertStringNotContainsString('제공하지 않습니다', $hint, '--yes 가 있는데 확인 생략 수단이 없다고 안내하지 않는다');
        }
    }
}
