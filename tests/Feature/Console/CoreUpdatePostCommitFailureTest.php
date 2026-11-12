<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * core:update 가 실패했을 때 거짓 복원 안내를 하지 않는지 잠근다.
 *
 * 코어 업데이트는 새 버전을 기록하고 백업을 지운 뒤에도 캐시 재생성·기록 저장·번들 확장 질문을 이어 간다.
 * 이 구간의 오류가 「전체 실패」 처리로 가면 지워진 백업에서 「복원 완료」라고 안내하고, 번들 배치 기록을 옛 상태로
 * 되돌리고, 성공 기록과 실패 기록을 둘 다 남겼다. 또 진행 막대를 만들기 전에 난 오류는 정리 코드가 없는 막대를
 * 건드려 원래 오류를 가렸다.
 *
 * Step 11 까지 실제로 태우는 시험은 캐시 재굽기와 프로세스 생성이 따라와 시험 규율(시험 중 캐시 재굽기 금지)과
 * 충돌한다. 그래서 확정 뒤 처리는 떼어 낸 메서드를 직접 부르고, handle() 의 갈래 순서는 소스로 확인한다.
 *
 * @scenario-manifest tests/scenarios/core-update-post-commit-failure.yaml
 */
class CoreUpdatePostCommitFailureTest extends TestCase
{
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
        $this->root = storage_path('test_core_update_post_commit_'.uniqid());
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
     * 진행 막대를 만들기 전에 난 오류도 원래 메시지 그대로 알린다.
     *
     * @scenario failure_point=before_progress_bar
     *
     * @effects early_failure_shows_original_error
     *
     * @return void 반환값 없음
     */
    public function test_error_before_progress_bar_shows_original_message(): void
    {
        $service = $this->mockService();
        $service->shouldReceive('checkSystemRequirements')->andReturn(['valid' => true, 'errors' => [], 'available_methods' => ['ZipArchive']]);
        $service->shouldReceive('checkForUpdates')->once()->andThrow(new \RuntimeException('release metadata unreadable'));
        $service->shouldNotReceive('restoreFromBackup');

        $this->artisan('core:update')
            ->expectsOutputToContain(__('settings.core_update.console.update_failed', ['error' => 'release metadata unreadable']))
            ->doesntExpectOutputToContain(__('settings.core_update.console.restored'))
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 확정 뒤 마무리 단계 오류는 「적용됐다 + 어느 단계 + 남은 일을 끝내는 명령」을 안내하고 실패로 끝난다.
     *
     * @scenario failure_point=after_commit
     *
     * @effects post_commit_failure_reports_applied_version_and_manual_steps
     *
     * @return void 반환값 없음
     */
    public function test_post_commit_failure_reports_applied_version_and_manual_steps(): void
    {
        $service = $this->mockService();
        $service->shouldNotReceive('restoreFromBackup');
        $service->shouldNotReceive('generateFailureReport');
        $service->shouldNotReceive('disableMaintenanceMode');

        [$command, $output] = $this->makeCommand();
        $exit = $this->invoke($command, 'reportPostCommitFailure', [
            new \RuntimeException('too many connections'), $service, '7.1.5', '7.1.6', 'bundled_extensions', false, true, [],
        ]);

        $display = $output->fetch();
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('7.1.6', $display);
        $this->assertStringContainsString(__('settings.core_update.console.post_commit_stage.bundled_extensions'), $display);
        $this->assertStringContainsString('too many connections', $display);
        foreach (['php artisan config:cache', 'php artisan route:cache', 'php artisan hooks:cache', 'php artisan module:update', 'php artisan plugin:update', 'php artisan template:update'] as $manual) {
            $this->assertStringContainsString($manual, $display);
        }
        $this->assertStringNotContainsString(__('settings.core_update.console.restored'), $display);
        $this->assertStringNotContainsString(__('settings.core_update.console.restoring_backup'), $display);
    }

    /**
     * 안내하는 명령은 모두 실재하는 명령이다.
     *
     * @scenario failure_point=after_commit
     *
     * @effects post_commit_failure_reports_applied_version_and_manual_steps
     *
     * @return void 반환값 없음
     */
    public function test_manual_step_commands_exist(): void
    {
        $registered = array_keys($this->app[Kernel::class]->all());

        foreach (['config:cache', 'route:cache', 'hooks:cache', 'module:update', 'plugin:update', 'template:update', 'module:list', 'plugin:list', 'template:list'] as $name) {
            $this->assertContains($name, $registered, $name);
        }
    }

    /**
     * 유지보수 해제 전에 실패했으면 새 버전으로 사이트를 열어 두고, 기록을 아직 못 남겼으면 성공 기록으로 남긴다.
     *
     * @scenario failure_point=after_commit
     *
     * @effects post_commit_failure_leaves_site_open_with_success_log
     *
     * @return void 반환값 없음
     */
    public function test_post_commit_failure_disables_maintenance_and_saves_success_log(): void
    {
        $service = $this->mockService();
        $service->shouldReceive('disableMaintenanceMode')->once();
        $service->shouldNotReceive('restoreFromBackup');

        [$command, $output] = $this->makeCommand();
        $exit = $this->invoke($command, 'reportPostCommitFailure', [
            new \RuntimeException('disk full'), $service, '7.1.5', '7.1.6', 'config_cache', true, false, ['[00:00:00] 정리 중'],
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $printed = $output->fetch();
        // 되돌린 것이 없으므로 「복원 완료」 계열 문구를 쓰지 않는다 — 유지보수 해제만 알린다
        $this->assertStringContainsString(__('settings.core_update.console.post_commit_maintenance_disabled'), $printed);
        $this->assertStringNotContainsString(__('settings.core_update.console.maintenance_disabled'), $printed);

        // 성공 기록 하나만 남는다 (실패 기록을 겹쳐 남기지 않는다)
        $logs = File::glob(storage_path('logs').DIRECTORY_SEPARATOR.'core_update_*.log');
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('success', basename($logs[0]));
    }

    /**
     * 백업 디렉토리가 없으면 복원할 수 있다고 보지 않는다(대상 0 을 「복원 완료」로 알리지 않는다).
     *
     * @scenario failure_point=before_commit_backup_missing
     *
     * @effects missing_backup_is_not_reported_as_restored
     *
     * @return void 반환값 없음
     */
    public function test_missing_backup_directory_is_not_restorable(): void
    {
        [$command] = $this->makeCommand();

        $this->assertFalse($this->invoke($command, 'backupIsRestorable', [null]));
        $this->assertFalse($this->invoke($command, 'backupIsRestorable', [$this->root.DIRECTORY_SEPARATOR.'no_such_backup']));
        $this->assertTrue($this->invoke($command, 'backupIsRestorable', [$this->root]));
    }

    /**
     * handle() 의 실패 갈래 — 백업 삭제 직후 확정으로 표시하고, 확정 뒤에는 복원·배치 기록 되돌림·실패 기록 앞에서 빠져나간다.
     *
     * @scenario failure_point=after_commit
     *
     * @effects post_commit_failure_skips_restore_and_record_rollback
     *
     * @return void 반환값 없음
     */
    public function test_handle_marks_commit_after_backup_deletion_and_returns_before_rollback(): void
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(app_path('Console/Commands/Core/CoreUpdateCommand.php')));

        // 선언은 try 앞에
        $tryAt = strpos($source, "        try {\n            // 코어 업데이트는 한 번에 하나만");
        $this->assertNotFalse($tryAt);
        foreach (['$bar = null;', '$committed = false;'] as $declaration) {
            $at = strpos($source, $declaration);
            $this->assertNotFalse($at, $declaration);
            $this->assertLessThan($tryAt, $at, $declaration);
        }

        // 백업 삭제 → 경로 비움 → 확정 표시 순서
        $deleteAt = strpos($source, 'CoreBackupHelper::deleteBackup($backupPath);');
        $nullAt = strpos($source, '$backupPath = null;', $deleteAt);
        $commitAt = strpos($source, '$committed = true;', $deleteAt);
        $this->assertNotFalse($deleteAt);
        $this->assertGreaterThan($deleteAt, $nullAt);
        $this->assertGreaterThan($nullAt, $commitAt);
        $this->assertLessThan(600, $commitAt - $deleteAt);

        // 일반 실패 갈래: 막대 정리는 null 안전, 확정 검사가 복원·기록 되돌림·실패 기록보다 앞
        $catchAt = strpos($source, '} catch (\Throwable $e) {'."\n".'            $bar?->finish();');
        $this->assertNotFalse($catchAt);
        $guardAt = strpos($source, 'if ($committed) {', $catchAt);
        $this->assertNotFalse($guardAt);
        foreach (['$service->restoreFromBackup($backupPath', '$this->restoreBundledRecord($planner', '$this->saveUpdateLog($logEntries, $fromVersion, $toVersion, false)', '$service->generateFailureReport('] as $rollback) {
            $at = strpos($source, $rollback, $catchAt);
            $this->assertNotFalse($at, $rollback);
            $this->assertGreaterThan($guardAt, $at, $rollback);
        }
        $this->assertStringContainsString('return $this->reportPostCommitFailure(', substr($source, $guardAt, 400));

        // 정의되지 않은 막대를 건드리는 자리가 남아 있지 않다
        $this->assertSame(0, preg_match('/catch \([^)]*\) \{\s*\$bar->finish\(\);/', $source));
    }

    /**
     * CoreUpdateService 목을 컨테이너에 등록합니다.
     *
     * @return MockInterface CoreUpdateService 목
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(CoreUpdateService::class);
        $service->shouldReceive('normalizeRuntimeOwnershipAfterRootRun')->byDefault();
        $service->shouldReceive('generateFailureReport')->andReturn('report.log')->byDefault();
        $this->app->instance(CoreUpdateService::class, $service);

        return $service;
    }

    /**
     * 출력 버퍼를 단 명령 인스턴스를 만듭니다.
     *
     * @return array{0: CoreUpdateCommand, 1: BufferedOutput} 명령과 출력 버퍼
     */
    private function makeCommand(): array
    {
        $command = app(CoreUpdateCommand::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput([]);
        $output = new BufferedOutput;

        $reflection = new \ReflectionClass($command);
        foreach (['output' => new OutputStyle($input, $output), 'input' => $input] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($command, $value);
        }

        return [$command, $output];
    }

    /**
     * 보호 메서드를 부릅니다.
     *
     * @param  CoreUpdateCommand  $command  명령
     * @param  string  $method  메서드 이름
     * @param  array<int, mixed>  $arguments  인자
     * @return mixed 메서드 반환값
     */
    private function invoke(CoreUpdateCommand $command, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($command, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($command, $arguments);
    }
}
