<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 웹 코어 업데이트의 번들 외 단계(내려받기·적용·의존성 반영·마이그레이션·업그레이드 스텝·마무리)가
 * 실행 기록에 터미널과 같은 수준의 내용(항목 수·검증 결과·적용 파일 수·교체/복사 구분·실행한 스텝 버전·
 * 마무리 하위 작업)을 남기는지 잰다.
 *
 * 7.2.0 화면은 실행 기록 줄만 보여 주므로, 이 줄이 운영자가 진행을 아는 유일한 수단이다.
 *
 * 커버 축: core_update_web step_log_detail
 */
class CoreUpdateStepLogDetailTest extends TestCase
{
    /** 격리 루트. */
    private string $root;

    /**
     * 격리 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_logdetail_'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 잠금을 풀고 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(CoreUpdateService::class)->releaseRunLock();
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 내려받기 완료 뒤 무결성 검증 결과가 서명 확인/미서명으로 구분된 한 줄로 남는다.
     *
     * @scenario run_state=running, source=github_download, failing_step=none
     *
     * @effects the_download_log_states_the_integrity_result
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_integrity_line_distinguishes_signed_and_unsigned_archives(): void
    {
        $orchestrator = $this->orchestrator(Mockery::mock(CoreUpdateService::class)->makePartial());

        $verified = $this->invokePrivate($orchestrator, 'describeArchiveIntegrity', ['status' => 'verified', 'key_id' => 'sirsoft:abc', 'signer_label' => 'SIRSOFT']);
        $this->assertSame((string) __('settings.core_update.web.log_integrity_verified', ['key' => 'sirsoft:abc', 'signer' => 'SIRSOFT']), $verified);
        $this->assertStringContainsString('sirsoft:abc', $verified);

        $unsigned = $this->invokePrivate($orchestrator, 'describeArchiveIntegrity', ['status' => 'unsigned', 'message' => '서명 파일 없음']);
        $this->assertSame((string) __('settings.core_update.web.log_integrity_unsigned', ['reason' => '서명 파일 없음']), $unsigned);
    }

    /**
     * 적용 완료 줄에 적용 파일 수(신규·변경)가, 산출 실패 시에는 전체 덮어쓰기임이 실린다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_apply_log_states_the_file_counts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_applied_line_carries_the_file_counts(): void
    {
        $orchestrator = $this->orchestrator(Mockery::mock(CoreUpdateService::class)->makePartial());

        $line = $this->invokePrivate($orchestrator, 'describeApplied', ['total' => 12, 'added_count' => 3, 'changed_count' => 9]);
        $this->assertSame((string) __('settings.core_update.web.log_applied_detail', ['total' => '12', 'added' => '3', 'changed' => '9']), $line);
        $this->assertStringContainsString('12', $line);

        $this->assertSame((string) __('settings.core_update.web.log_applied_full'), $this->invokePrivate($orchestrator, 'describeApplied', null));
    }

    /**
     * 업그레이드 스텝 줄은 실행한 스텝 버전과 이미 실행되어 건너뛴 스텝을 터미널처럼 나눠 보여 준다.
     *
     * @scenario run_state=running, handoff=none, failing_step=none
     *
     * @effects the_upgrade_steps_log_names_the_step_versions
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_upgrade_steps_lines_name_the_versions(): void
    {
        $orchestrator = $this->orchestrator(Mockery::mock(CoreUpdateService::class)->makePartial());

        $lines = $this->invokePrivate($orchestrator, 'describeUpgradeSteps', [
            'count' => 2, 'discovered' => 3, 'skipped' => 1,
            'versions' => ['7.2.1-01', '7.2.1-02'], 'skippedVersions' => ['7.2.0'],
        ], '7.2.0', '7.2.1');

        $this->assertSame([
            (string) __('settings.core_update.web.log_upgrade_steps_ran', ['count' => '2', 'versions' => '7.2.1-01, 7.2.1-02']),
            (string) __('settings.core_update.web.log_upgrade_steps_already', ['count' => '1', 'versions' => '7.2.0']),
        ], $lines);

        $none = $this->invokePrivate($orchestrator, 'describeUpgradeSteps', ['count' => 0, 'discovered' => 0, 'skipped' => 0, 'versions' => [], 'skippedVersions' => []], '7.2.0', '7.2.1');
        $this->assertSame([(string) __('settings.core_update.web.log_upgrade_steps_none', ['from' => '7.2.0', 'to' => '7.2.1'])], $none);

        // 버전 목록이 없는 종료 신호(구버전 명령)는 종전 줄을 그대로 남긴다
        $legacy = $this->invokePrivate($orchestrator, 'describeUpgradeSteps', ['count' => 4, 'discovered' => 4], '7.2.0', '7.2.1');
        $this->assertSame([(string) __('settings.core_update.web.log_upgrade_steps', ['count' => '4'])], $legacy);
    }

    /**
     * 마이그레이션 줄은 이번에 실행된 마이그레이션 수와 이름을 싣고, 많으면 앞 10개만 보인 뒤 나머지 수를 붙인다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_migrate_log_names_the_migrations_that_ran
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_migration_line_lists_what_ran(): void
    {
        $orchestrator = $this->orchestrator(Mockery::mock(CoreUpdateService::class)->makePartial());

        $this->assertSame((string) __('settings.core_update.web.log_migrations_none'), $this->invokePrivate($orchestrator, 'describeMigrations', []));

        $names = array_map(static fn (int $i): string => sprintf('2026_11_%02d_000000_m', $i), range(1, 12));
        $line = $this->invokePrivate($orchestrator, 'describeMigrations', $names);

        $this->assertStringContainsString('2026_11_10_000000_m', $line);
        $this->assertStringNotContainsString('2026_11_11_000000_m', $line);
        $this->assertStringContainsString((string) __('settings.core_update.web.log_list_more', ['count' => '2']), $line);
        $this->assertStringStartsWith((string) __('settings.core_update.web.log_migrations_ran', ['count' => '12', 'names' => '']), $line);

        // 저장소 조회는 읽기만 하고 실패해도 업데이트를 막지 않는다(목록 또는 null)
        $ran = $this->invokePrivate($orchestrator, 'ranMigrationNames');
        $this->assertTrue($ran === null || is_array($ran));
    }

    /**
     * 의존성 반영 줄은 vendor 를 통째로 바꿔 끼운 경우와 파일을 복사한 경우를 다른 문구로 남긴다.
     *
     * @scenario run_state=running, execution_mode=inprocess, failing_step=none
     *
     * @effects the_vendor_copy_log_distinguishes_swap_and_copy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_vendor_line_distinguishes_swap_and_copy(): void
    {
        foreach ([[true, 'log_vendor_swapped'], [false, 'log_vendor_copied_files']] as [$swapped, $key]) {
            $service = Mockery::mock(CoreUpdateService::class)->makePartial();
            $service->shouldReceive('resolveStagingRoot')->andReturn($this->root);
            $service->shouldReceive('swapVendorFromPending')->andReturn((bool) $swapped);
            $service->shouldReceive('copyVendorFromPending')->andReturnNull();

            $state = $this->freshState(CoreUpdateStep::VendorCopy);
            $state->mergeContext(['pending_path' => $this->root.DIRECTORY_SEPARATOR.'pending']);

            $this->invokePrivate($this->orchestrator($service), 'stepVendorCopy');

            $this->assertTrue(
                $this->hasLogEndingWith((string) __('settings.core_update.web.'.$key)),
                $key.' 줄이 남아야 한다',
            );
        }
    }

    /**
     * 마무리 첫 구간(버전 기록·캐시 비우기·재시작 신호)이 끝나면 그 사실이 한 줄로 남는다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_finalize_log_reports_each_sub_task
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_finalize_prepare_phase_is_logged(): void
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('updateVersionInEnv')->andReturnNull();
        $service->shouldReceive('clearAllCaches')->andReturnNull();
        $service->shouldReceive('signalQueueRestart')->andReturnNull();

        $this->freshState(CoreUpdateStep::Finalize);

        // 몫이 곧바로 바닥나는 예산 — 준비 구간만 하고 멈춘다(캐시 굽기는 개발 저장소를 건드린다)
        $tick = 0.0;
        $budget = new CoreUpdateTimeBudget(static function () use (&$tick): float {
            return $tick += 100.0;
        }, 30);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), $budget))->advance();

        $this->assertSame(CoreUpdateStep::Finalize, $this->state()->nextStep());
        $this->assertTrue($this->hasLogEndingWith((string) __('settings.core_update.web.log_finalize_prepared')));
        $this->assertTrue($this->hasLogEndingWith((string) __('settings.core_update.web.log_finalize_autoload_rebuilt')), '확장 오토로드·훅 캐시를 다시 만든 사실이 기록에 없다');
    }

    /**
     * 마무리의 확장 오토로드·훅 캐시 재생성이 실패하면 터미널처럼 운영자가 직접 실행할 명령을 경고로 남긴다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_finalize_warns_with_the_manual_command_when_the_hook_cache_rebuild_fails
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_failed_hook_cache_rebuild_leaves_a_warning_with_the_manual_command(): void
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('updateVersionInEnv')->andReturnNull();
        $service->shouldReceive('clearAllCaches')->andReturnNull();
        $service->shouldReceive('signalQueueRestart')->andReturnNull();
        $service->shouldReceive('autoloadRefreshFailed')->andReturnTrue();

        $this->freshState(CoreUpdateStep::Finalize);

        $tick = 0.0;
        $budget = new CoreUpdateTimeBudget(static function () use (&$tick): float {
            return $tick += 100.0;
        }, 30);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), $budget))->advance();

        $message = (string) __('settings.core_update.web.log_finalize_autoload_failed');
        $this->assertStringContainsString('php artisan extension:update-autoload', $message);
        $this->assertContains($message, $this->state()->all()['warnings'] ?? [], '재생성 실패가 경고로 남지 않았다');
        $this->assertTrue($this->hasLogEndingWith($message));
    }

    /**
     * 격리 경로를 쓰는 상태를 만든다.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
    }

    /**
     * 주어진 단계에서 시작하는 실행 상태를 만든다.
     *
     * @param  CoreUpdateStep  $step  다음 단계
     * @return CoreUpdateRunState 상태
     */
    private function freshState(CoreUpdateStep $step): CoreUpdateRunState
    {
        File::deleteDirectory($this->root.DIRECTORY_SEPARATOR.'state');
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => $step->value]);

        return $state;
    }

    /**
     * 격리 상태를 쓰는 오케스트레이터를 만든다.
     *
     * @param  CoreUpdateService  $service  서비스 대역
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function orchestrator(CoreUpdateService $service): CoreUpdateOrchestrator
    {
        return new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300));
    }

    /**
     * 오케스트레이터의 비공개 메서드를 부른다.
     *
     * @param  CoreUpdateOrchestrator  $orchestrator  대상
     * @param  string  $method  메서드 이름
     * @param  mixed  ...$args  인자
     * @return mixed 반환값
     */
    private function invokePrivate(CoreUpdateOrchestrator $orchestrator, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($orchestrator, $method);

        return $reflection->invoke($orchestrator, ...$args);
    }

    /**
     * 실행 기록에 주어진 문구로 끝나는 줄이 있는지 본다 (줄 앞의 시각 표식은 무시).
     *
     * @param  string  $message  문구
     * @return bool 있으면 true
     */
    private function hasLogEndingWith(string $message): bool
    {
        foreach ($this->state()->logs() as $line) {
            if (str_ends_with((string) $line, $message)) {
                return true;
            }
        }

        return false;
    }
}
