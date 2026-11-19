<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Services\CoreUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 확인 질문에 답할 수 없는 `core:update` 실행이 성공으로 끝나지 않는지 잰다.
 *
 * cron, CI, 원격 명령(`ssh host 'php artisan core:update'`), `--no-interaction` 은 진행 확인에 답할 수 없다.
 * 종전에는 그 확인이 기본값 「아니오」로 처리돼 아무 출력 없이 종료 코드 0 으로 끝났고, 자동화는 업데이트가
 * 끝난 줄 알았다. 지금은 사유와 `--yes` 안내를 출력하고 종료 코드 1 로 끝난다. `--yes` 는 그 확인을 「예」로
 * 처리한다. 대화형에서 운영자가 직접 「아니오」를 답한 취소는 종전처럼 0 이다.
 *
 * CoreUpdateService 는 목으로 바꿔 확인 단계(와 그 바로 뒤 _pending 검증)까지만 실제 커맨드를 돌린다 —
 * 유지보수 모드·다운로드·파일 적용은 한 번도 일어나지 않는다.
 */
class CoreUpdateNonInteractiveConfirmTest extends TestCase
{
    use DatabaseTransactions;

    private string $root;

    private string $originalStoragePath;

    /**
     * 격리 storage 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = storage_path('test_core_update_confirm_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');

        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * storage 경로와 업데이트 진행 플래그를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        if (File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * `--no-interaction` 실행은 확인을 받지 못한 취소를 사유와 함께 실패로 끝내고 업데이트를 시작하지 않는다.
     *
     * @scenario input=no_interaction_option, yes_option=absent
     *
     * @effects unanswerable_confirmation_fails_with_reason_and_hint, update_does_not_start_without_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_no_interaction_run_without_yes_fails_with_reason(): void
    {
        $service = $this->serviceReachingConfirmation();
        $this->expectNoUpdateWork($service);

        $this->artisan('core:update', ['--no-interaction' => true])
            ->expectsOutputToContain(__('settings.core_update.console.confirm_unavailable'))
            ->expectsOutputToContain(__('settings.core_update.console.confirm_unavailable_hint'))
            ->assertExitCode(1);
    }

    /**
     * 터미널 없이 부른 실행(입력이 콘솔이 아님)도 같은 규칙을 따른다 — `--no-interaction` 을 붙이지 않은 원격 명령.
     *
     * @scenario input=no_terminal, yes_option=absent
     *
     * @effects unanswerable_confirmation_fails_with_reason_and_hint, update_does_not_start_without_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_run_without_terminal_and_without_yes_fails_with_reason(): void
    {
        $service = $this->serviceReachingConfirmation();
        $this->expectNoUpdateWork($service);
        $this->useCommandWithoutTerminal();

        $this->artisan('core:update')
            ->expectsOutputToContain(__('settings.core_update.console.confirm_unavailable'))
            ->expectsOutputToContain(__('settings.core_update.console.confirm_unavailable_hint'))
            ->assertExitCode(1);
    }

    /**
     * `--yes` 는 답할 수 없는 실행에서 진행 확인을 「예」로 처리해 다음 단계(_pending 검증)로 넘어간다.
     *
     * @scenario input=no_interaction_option, yes_option=present
     *
     * @effects yes_option_answers_the_update_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_no_interaction_run_with_yes_passes_the_confirmation(): void
    {
        $service = $this->serviceReachingConfirmation();
        $this->stopAtPendingValidation($service);

        $this->artisan('core:update', ['--no-interaction' => true, '--yes' => true])
            ->doesntExpectOutputToContain(__('settings.core_update.console.confirm_unavailable'))
            ->expectsOutputToContain(__('settings.core_update.console.pending_invalid'))
            ->assertExitCode(1);
    }

    /**
     * 터미널 없는 실행에서도 `--yes` 는 확인을 통과시킨다.
     *
     * @scenario input=no_terminal, yes_option=present
     *
     * @effects yes_option_answers_the_update_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_run_without_terminal_with_yes_passes_the_confirmation(): void
    {
        $service = $this->serviceReachingConfirmation();
        $this->stopAtPendingValidation($service);
        $this->useCommandWithoutTerminal();

        $this->artisan('core:update', ['--yes' => true])
            ->doesntExpectOutputToContain(__('settings.core_update.console.confirm_unavailable'))
            ->expectsOutputToContain(__('settings.core_update.console.pending_invalid'))
            ->assertExitCode(1);
    }

    /**
     * 대화형에서 `--yes` 를 붙이면 진행 확인을 묻지 않는다 (질문이 나오면 테스트 목이 예기치 않은 질문으로 실패한다).
     *
     * @scenario input=interactive, yes_option=present
     *
     * @effects yes_option_answers_the_update_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_interactive_run_with_yes_does_not_ask(): void
    {
        $service = $this->serviceReachingConfirmation();
        $this->stopAtPendingValidation($service);

        $this->artisan('core:update', ['--yes' => true])
            ->doesntExpectOutputToContain(__('settings.core_update.console.confirm_unavailable'))
            ->expectsOutputToContain(__('settings.core_update.console.pending_invalid'))
            ->assertExitCode(1);
    }

    /**
     * 대화형에서 운영자가 직접 「아니오」를 답한 취소는 종전처럼 종료 코드 0 이고 사유 안내를 내지 않는다.
     *
     * @scenario input=interactive, yes_option=absent
     *
     * @effects interactive_no_answer_keeps_success_exit_code, update_does_not_start_without_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_interactive_no_answer_keeps_success_exit_code(): void
    {
        $service = $this->serviceReachingConfirmation();
        $this->expectNoUpdateWork($service);

        $this->artisan('core:update')
            ->expectsQuestion(__('settings.core_update.console.confirm_update').' (yes/no) [no]', 'no')
            ->doesntExpectOutputToContain(__('settings.core_update.console.confirm_unavailable'))
            ->assertExitCode(0);
    }

    /**
     * 진행 확인 직전까지 가는 CoreUpdateService 목을 컨테이너에 넣습니다 (GitHub 경로, 새 버전 있음).
     *
     * @return MockInterface CoreUpdateService 목
     */
    private function serviceReachingConfirmation(): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('acquireRunLock')->andReturn(true)->byDefault();
        $service->shouldReceive('releaseRunLock')->byDefault();
        $service->shouldReceive('getLastRootFileWarnings')->andReturn([])->byDefault();
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun')->byDefault();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldReceive('checkForUpdates')->andReturn(['latest_version' => '7.9.9', 'update_available' => true]);
        // 패키지 서명 검증(7.2.0)은 이 시험의 관심사가 아니다 — 통과(미서명 확인됨)로 둔다
        $service->shouldReceive('preflightReleaseIntegrity')->byDefault();
        $service->shouldReceive('verifyCoreZipIntegrity')->andReturn(\App\Extension\Integrity\PackageVerification::unsigned())->byDefault();
        $service->shouldReceive('confirmCoreIntegrity')->andReturnUsing(fn ($verification) => $verification)->byDefault();
        $this->app->instance(CoreUpdateService::class, $service);

        return $service;
    }

    /**
     * 확인 뒤의 어떤 단계도 일어나지 않아야 함을 목에 겁니다.
     *
     * @param  MockInterface  $service  CoreUpdateService 목
     * @return void 반환값 없음
     */
    private function expectNoUpdateWork(MockInterface $service): void
    {
        $service->shouldNotReceive('validatePendingPath');
        $service->shouldNotReceive('enableMaintenanceMode');
        $service->shouldNotReceive('downloadUpdate');
        $service->shouldNotReceive('createBackup');
        $service->shouldNotReceive('applyUpdate');
    }

    /**
     * 확인을 통과한 실행을 Step 2(_pending 검증)에서 실패시켜 유지보수 모드 전에 멈춥니다.
     *
     * @param  MockInterface  $service  CoreUpdateService 목
     * @return void 반환값 없음
     */
    private function stopAtPendingValidation(MockInterface $service): void
    {
        $service->shouldReceive('validatePendingPath')->once()->andReturn([
            'valid' => false, 'errors' => ['stop'], 'path' => 'p', 'owner' => 'o', 'group' => 'g', 'permissions' => '0755',
        ]);
        $service->shouldNotReceive('enableMaintenanceMode');
    }

    /**
     * 입력이 콘솔이 아닌 실행을 흉내 내는 `core:update` 를 등록합니다.
     *
     * 테스트 실행에서는 `canPromptForAnswer()` 가 늘 true 라(테스트가 입력을 주입하므로) 그 판정만 바꾼다.
     * Windows 처럼 posix 확장이 없어 `--no-interaction` 없이도 대화형으로 남는 원격 실행이 이 경로다.
     *
     * @return void 반환값 없음
     */
    private function useCommandWithoutTerminal(): void
    {
        $command = new class extends CoreUpdateCommand
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
        };

        $this->app[Kernel::class]->registerCommand($command);
    }
}
