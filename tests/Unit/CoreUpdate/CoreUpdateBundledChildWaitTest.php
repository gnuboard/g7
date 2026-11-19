<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\Contracts\Repositories\ExtensionUpdateJournalRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\CoreUpdate\BundledChildMarker;
use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Execution\CommandRunResult;
use App\Execution\Contracts\CommandRunner;
use App\Execution\ExecutionMode;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Models\Module;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 관리자 화면 코어 업데이트가 끊긴 번들 항목의 자식 프로세스가 끝나기를 기다리는 규칙.
 *
 * 요청 시간 제한으로 기다리던 요청이 죽어도 자식(`core:execute-bundled-updates`)은 끝까지 돌 수 있다. 다음 요청이
 * 바로 다음 항목·마무리로 가면 두 프로세스가 같은 확장·캐시를 동시에 바꾼다. 자식은 실행 상태 디렉토리의 표식 파일을 잠근 채
 * 자기 PID 를 적고, 다음 조각은 그 잠금(잡히지 않으면 OS 의 PID 생존 확인)으로 종료를 확인한 뒤 진행한다.
 *
 * 커버 축: core_update_bundled_child_wait
 */
class CoreUpdateBundledChildWaitTest extends TestCase
{
    /** 살아 있을 수 없는 PID (고아 시험용 — 같은 호스트의 PID 생존 확인에 걸리지 않게) */
    private const DEAD_PID = 99999996;

    /** 격리 루트 */
    private string $root;

    /** @var resource|null 시험이 「살아 있는 자식」 대신 잡는 잠금 */
    private $held = null;

    /**
     * 격리 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_bundled_child_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'state');
    }

    /**
     * 잠금을 놓고 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_resource($this->held)) {
            flock($this->held, LOCK_UN);
            fclose($this->held);
        }

        Cache::lock(ExtensionOperationLock::key('module', 'sirsoft-page'))->forceRelease();
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 자식이 아직 표식을 잠그고 있으면 다음 항목을 돌리지 않고 같은 단계를 다시 하며, 자식 PID 를 실행 상태에 적고 기다림을 알린다.
     *
     * @scenario case=child_running
     *
     * @effects next_item_not_started_while_child_alive, child_pid_recorded_in_run_state, waiting_notice_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_waits_while_the_interrupted_child_still_holds_its_marker(): void
    {
        $marker = $this->interruptedRun();
        $this->holdMarker($marker, ['pid' => 4242]);

        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldNotReceive('resolve');

        $this->advance($runners);

        $after = $this->state();
        $this->assertSame(CoreUpdateStep::Bundled->value, $after->nextStep()?->value, '자식이 도는 동안 번들 단계를 끝냈다');
        $this->assertSame(1, (int) $after->context('bundled_progress')['index'], '자식이 도는 동안 다음 항목으로 넘어갔다');
        $child = (array) $after->context('bundled_child');
        $this->assertSame(4242, $child['pid'] ?? null, '자식 PID 를 실행 상태에 적지 않았다');
        $this->assertIsInt($child['waiting_since'] ?? null);
        $waiting = (string) __('settings.core_update.web.log_bundled_child_waiting', ['identifier' => 'sirsoft-basic', 'pid' => '4242']);
        $this->assertNotEmpty(array_filter($after->logs(), static fn (string $line): bool => str_ends_with($line, $waiting)), '기다림 안내가 없다');
    }

    /**
     * 자식이 끝나 표식에 완료를 적고 잠금을 놓았으면 표식을 치우고 다음 항목을 돈다.
     *
     * @scenario case=child_finished
     *
     * @effects proceeds_after_child_finished, marker_removed_after_check, interrupted_item_manifest_removed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_proceeds_once_the_child_has_finished(): void
    {
        $marker = $this->interruptedRun();
        File::put($marker, '');
        File::put(BundledChildMarker::infoPath($marker), (string) json_encode(['pid' => 4242, 'done' => true]));

        $this->advance($this->runnerReturningSuccess());

        $after = $this->state();
        $this->assertNull($after->context('bundled_child'));
        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist(BundledChildMarker::infoPath($marker));
        $this->assertFileDoesNotExist($this->manifestPath(), '끊긴 요청이 남긴 항목 매니페스트를 치우지 않았다');
        $this->assertSame(['success' => 1, 'failed' => 0, 'unconfirmed' => 1, 'planned' => 2], $after->context('bundled_result'));
    }

    /**
     * 표식은 남았는데 잡고 있는 프로세스가 없고 완료도 적히지 않았으면(재부팅·강제 종료로 남은 고아) 기다리지 않고 치운 뒤 진행한다.
     *
     * @scenario case=orphan_marker
     *
     * @effects orphan_marker_cleared_without_waiting, interrupted_item_manifest_removed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_orphan_marker_is_cleared_without_waiting(): void
    {
        $marker = $this->interruptedRun();
        File::put($marker, '');
        File::put(BundledChildMarker::infoPath($marker), (string) json_encode(['pid' => self::DEAD_PID]));

        $this->advance($this->runnerReturningSuccess());

        $after = $this->state();
        $this->assertSame(CoreUpdateStep::Finalize->value, $after->nextStep()?->value);
        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist($this->manifestPath(), '끊긴 요청이 남긴 항목 매니페스트를 치우지 않았다');
        $orphan = (string) __('settings.core_update.web.log_bundled_child_orphaned', ['identifier' => 'sirsoft-basic', 'pid' => (string) self::DEAD_PID]);
        $this->assertNotEmpty(array_filter($after->logs(), static fn (string $line): bool => str_ends_with($line, $orphan)), '고아 표식을 치운 기록이 없다');
    }

    /**
     * 기다림 상한을 넘기면 더 기다리지 않고 확인 안내를 남긴 뒤 진행한다(끝나지 않는 자식에 사이트를 점검 모드로 가두지 않는다).
     *
     * @scenario case=wait_timeout
     *
     * @effects proceeds_with_warning_after_wait_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_gives_up_waiting_after_the_limit_with_a_warning(): void
    {
        $marker = $this->interruptedRun(waitingSince: time() - CoreUpdateOrchestrator::BUNDLED_CHILD_WAIT_SECONDS - 5);
        $this->holdMarker($marker, ['pid' => 4242]);

        $this->advance($this->runnerReturningSuccess());

        $after = $this->state();
        $this->assertSame(CoreUpdateStep::Finalize->value, $after->nextStep()?->value);
        $this->assertContains((string) __('settings.core_update.web.bundled_child_wait_timeout', [
            'identifier' => 'sirsoft-basic',
            'pid' => '4242',
            'minutes' => (string) intdiv(CoreUpdateOrchestrator::BUNDLED_CHILD_WAIT_SECONDS, 60),
        ]), $after->all()['warnings'] ?? []);
    }

    /**
     * 항목을 돌릴 때 표식 경로를 실행 상태와 자식 매니페스트에 함께 싣고, 정상으로 돌아오면 실행 상태에서 지운다.
     *
     * @scenario case=marker_wiring
     *
     * @effects marker_path_passed_to_child_and_cleared_on_return
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_item_run_passes_the_marker_to_the_child_and_clears_it_on_return(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep']],
            'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => 1,
        ]]);

        $seen = [];
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function (string $_command, array $parameters) use (&$seen): CommandRunResult {
            $seen['manifest'] = json_decode((string) file_get_contents((string) $parameters['--manifest']), true);
            $seen['child'] = $this->state()->context('bundled_child');

            return new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Subprocess);
        });
        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldReceive('resolve')->andReturn($runner);

        $this->advance($runners);

        $this->assertIsArray($seen['child'] ?? null, '항목을 돌리는 동안 실행 상태에 자식 표식이 없다');
        $this->assertSame('sirsoft-basic', $seen['child']['identifier']);
        $this->assertSame($seen['child']['marker'], $seen['manifest']['child_marker'] ?? null, '자식 매니페스트에 표식 경로가 없다');
        $this->assertSame('template', $seen['child']['type'] ?? null);
        $this->assertArrayHasKey('prior_status', $seen['child'], '업데이트 전 확장 상태를 실행 상태에 적지 않았다');
        $this->assertStringContainsString('bundled-updates-manifest_', (string) ($seen['child']['manifest'] ?? ''), '항목 매니페스트 경로를 실행 상태에 적지 않았다');
        $this->assertSame(dirname($state->path()), dirname((string) $seen['child']['marker']), '표식은 실행 상태 디렉토리에 둔다');
        $this->assertNull($this->state()->context('bundled_child'), '정상으로 돌아온 항목의 표식이 실행 상태에 남았다');
    }

    /**
     * 자식 쪽: 표식을 잡는 동안 다른 프로세스의 검사는 「도는 중」, 놓으면 「끝남」이고 파일은 사라진다. PID 생존 확인은 이 프로세스를
     * 살아 있다고 답한다(Windows `tasklist` · Linux `posix_kill`/`/proc`).
     *
     * @scenario case=marker_lifecycle
     *
     * @effects hold_reports_running_release_reports_finished, pid_liveness_detects_current_process, child_records_lock_owner_in_marker
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_marker_hold_and_release_and_pid_liveness(): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'bundled-child_x.lock';

        $handle = BundledChildMarker::hold($path);
        $this->assertNotNull($handle);
        $probe = BundledChildMarker::inspect($path);
        $this->assertSame(BundledChildMarker::RUNNING, $probe['state']);
        $this->assertSame(getmypid(), $probe['pid']);

        // 표식을 잡은 자식이 확장 작업 잠금을 잡으면 그 소유자를 표식에 적는다 (죽으면 부모가 그 소유자로만 푼다)
        $operation = ExtensionOperationLock::acquire('module', 'sirsoft-page');
        $locks = BundledChildMarker::inspect($path)['locks'];
        $key = ExtensionOperationLock::key('module', 'sirsoft-page');
        $this->assertArrayHasKey($key, $locks, '자식이 잡은 작업 잠금의 소유자를 표식에 적지 않았다');
        $this->assertFalse(ExtensionOperationLock::releaseAbandonedKey($key, 'not-the-owner'), '소유자가 아닌 토큰으로 잠금을 풀었다');
        $this->assertTrue(ExtensionOperationLock::releaseAbandonedKey($key, $locks[$key]));
        $operation->release();

        BundledChildMarker::release($handle, $path);
        $this->assertFileDoesNotExist($path);
        // 완료 표지(정보 파일)는 남는다 — 부모가 「끝남」과 「아직 표식을 잡기 전」을 가르는 근거
        $this->assertTrue((bool) (json_decode((string) file_get_contents(BundledChildMarker::infoPath($path)), true)['done'] ?? false));
        $this->assertSame(BundledChildMarker::FINISHED, BundledChildMarker::inspect($path, time())['state'], '완료 표지가 있는데 띄운 직후라며 도는 중으로 봤다');
        $this->assertFileDoesNotExist(BundledChildMarker::infoPath($path), '부모 판정 뒤 완료 표지가 남았다');

        $this->assertTrue(BundledChildMarker::pidAlive(getmypid()), '살아 있는 이 프로세스를 죽었다고 판정했다');
        $this->assertNotTrue(BundledChildMarker::pidAlive(0));
        $this->assertNull(BundledChildMarker::hold($this->root.DIRECTORY_SEPARATOR.'outside.txt'), '표식 이름 규약 밖의 경로를 잡았다');
    }

    /**
     * 고아로 판정한 자식(죽은 것을 확인)이 쥐고 있던 확장 작업 잠금과 `updating` 상태를 정리한다 — 파일을 바꾸기 전에 죽었으면
     * 업데이트 전 상태(활성)로 되돌려, 화면 안내대로 바로 개별 업데이트할 수 있다(10분 잠금 만료·`--force`·활성화 3단계가 필요 없다).
     *
     * @scenario case=orphan_extension_recovery
     *
     * @effects orphan_releases_extension_lock, orphan_restores_prior_status_when_files_untouched, orphan_recovery_logged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_orphan_releases_the_extension_lock_and_restores_the_prior_status(): void
    {
        $captured = $this->orphanedModuleRun(journal: null);

        $this->assertTrue(ExtensionOperationLock::isFree('module', 'sirsoft-page'), '죽은 자식이 쥔 확장 작업 잠금이 남았다');
        $this->assertSame('active', $captured['status'] ?? null, '업데이트 전 상태(활성)로 되돌리지 않았다');
        $this->assertArrayNotHasKey('version', $captured);
        $recovered = (string) __('settings.core_update.web.log_bundled_child_orphan_recovered', ['identifier' => 'sirsoft-page', 'status' => 'active']);
        $this->assertNotEmpty(array_filter($this->state()->logs(), static fn (string $line): bool => str_ends_with($line, $recovered)), '확장 상태를 정리한 기록이 없다');
    }

    /**
     * 파일을 바꾸기 시작한 뒤 죽었으면(진행 기록) 판을 시작 판으로 되돌려 「업데이트 있음」으로 두고 비활성으로 정리한다 — 반쯤 바뀐
     * 파일로 켜지 않는다(멈춘 업데이트 정리와 같은 결정). 다음 개별 업데이트가 `--force` 없이 판 전환을 마친다.
     *
     * @scenario case=orphan_extension_recovery
     *
     * @effects orphan_reverts_version_and_deactivates_when_files_touched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_orphan_with_touched_files_reverts_the_version_and_leaves_it_inactive(): void
    {
        $captured = $this->orphanedModuleRun(journal: new ExtensionUpdateJournal('module', 'sirsoft-page', [
            'from_version' => '1.3.5', 'to_version' => '1.3.6', 'source' => 'bundled', 'files_touched' => true,
            'completed_steps' => [], 'started_at' => now()->toIso8601String(), 'resumed' => false,
        ]));

        $this->assertTrue(ExtensionOperationLock::isFree('module', 'sirsoft-page'));
        $this->assertSame('inactive', $captured['status'] ?? null);
        $this->assertSame('1.3.5', $captured['version'] ?? null, '시작 판으로 되돌리지 않았다');
        $this->assertTrue($captured['update_available'] ?? false, '「업데이트 있음」으로 두지 않았다');
    }

    /**
     * 부모 요청이 살아 있는 동안 자식만 결과 없이 죽으면(강제 종료·신호) 그 자리에서 고아와 같은 정리를 한다 — 자식이 쥐었던 확장
     * 작업 잠금을 풀고 `updating` 을 업데이트 전 상태로 되돌리며 표식을 치운다. 그러지 않으면 「개별로 다시 시도」와 바로 이어 하는
     * 코어 업데이트가 잠금 수명(10분) 동안 그 확장에서 거절된다.
     *
     * @scenario case=child_died_while_parent_alive
     *
     * @effects abnormal_child_exit_releases_its_lock, abnormal_child_exit_restores_status, abnormal_child_exit_clears_marker
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_child_dying_while_the_parent_waits_releases_its_lock_and_status(): void
    {
        $captured = [];
        $markers = [];
        $this->moduleItemRun(
            function (array $child) use (&$markers): CommandRunResult {
                // 죽은 자식: 표식·정보는 남고(완료 없음) 잠금은 그 자식 소유로 남는다
                $markers[] = $child['marker'];
                File::put($child['marker'], '');
                $key = ExtensionOperationLock::key('module', 'sirsoft-page');
                File::put(BundledChildMarker::infoPath($child['marker']), (string) json_encode(['pid' => self::DEAD_PID, 'locks' => [$key => 'dead-child']]));
                $this->assertTrue(Cache::lock($key, 600, 'dead-child')->get());

                return new CommandRunResult(-1, 'Terminated', ExecutionMode::Subprocess);
            },
            $captured,
        );

        $this->assertTrue(ExtensionOperationLock::isFree('module', 'sirsoft-page'), '죽은 자식이 쥔 확장 작업 잠금이 남았다');
        $this->assertSame('active', $captured['status'] ?? null, '`updating` 을 업데이트 전 상태로 되돌리지 않았다');
        $this->assertFileDoesNotExist($markers[0]);
        $this->assertFileDoesNotExist(BundledChildMarker::infoPath($markers[0]));
        $this->assertSame(1, (int) ($this->state()->context('bundled_result')['failed'] ?? 0));
    }

    /**
     * 자식이 결과를 남기고 정상 종료하며 실패를 보고했으면 자식이 이미 정리했다 — 부모는 잠금·상태를 건드리지 않는다(지금 잠금을
     * 쥔 것은 다른 작업일 수 있다).
     *
     * @scenario case=child_reported_failure
     *
     * @effects reported_failure_leaves_lock_and_status_alone
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_reported_failure_leaves_other_owners_lock_and_status_alone(): void
    {
        $captured = [];
        $key = ExtensionOperationLock::key('module', 'sirsoft-page');
        $this->moduleItemRun(
            function (array $child) use ($key): CommandRunResult {
                $this->assertTrue(Cache::lock($key, 600, 'someone-else')->get());

                return new CommandRunResult(1, '[BUNDLED-RESULT] {"success":0,"failed":1}', ExecutionMode::Subprocess);
            },
            $captured,
        );

        $this->assertFalse(ExtensionOperationLock::isFree('module', 'sirsoft-page'), '정상 실패 보고 뒤 다른 작업의 잠금을 풀었다');
        $this->assertSame([], $captured, '정상 실패 보고 뒤 확장 상태를 고쳤다');
    }

    /**
     * 죽은 자식이 기록한 잠금 소유자와 지금 잠금 소유자가 다르면(그새 다른 작업이 잡음) 그 잠금은 풀지 않는다.
     *
     * @scenario case=child_died_while_parent_alive
     *
     * @effects lock_with_other_owner_not_released
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_lock_now_owned_by_someone_else_is_not_released(): void
    {
        $captured = [];
        $key = ExtensionOperationLock::key('module', 'sirsoft-page');
        $this->moduleItemRun(
            function (array $child) use ($key): CommandRunResult {
                File::put($child['marker'], '');
                File::put(BundledChildMarker::infoPath($child['marker']), (string) json_encode(['pid' => self::DEAD_PID, 'locks' => [$key => 'dead-child']]));
                $this->assertTrue(Cache::lock($key, 600, 'someone-else')->get());

                return new CommandRunResult(-1, '', ExecutionMode::Subprocess);
            },
            $captured,
        );

        $this->assertFalse(ExtensionOperationLock::isFree('module', 'sirsoft-page'), '다른 소유자의 잠금을 풀었다');
    }

    /**
     * 결과 줄 없이 끝났지만 표식에 그 자식이 잡은 잠금 기록이 없으면(표식을 잡기 전에 끝났거나 스스로 정리하고 끝남) 그 확장의
     * 잠금·상태는 자식 것이라는 근거가 없다 — 다른 작업(운영자의 명령줄 업데이트 등)의 것일 수 있어 건드리지 않고 만료에 맡긴다.
     *
     * @scenario case=child_died_while_parent_alive
     *
     * @effects no_lock_record_leaves_lock_and_status_alone
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_without_a_lock_record_nothing_is_released_or_reverted(): void
    {
        $captured = [];
        $key = ExtensionOperationLock::key('module', 'sirsoft-page');
        $this->moduleItemRun(
            function (array $child) use ($key): CommandRunResult {
                $this->assertTrue(Cache::lock($key, 600, 'operator-cli')->get());

                return new CommandRunResult(255, 'PHP Fatal error', ExecutionMode::Subprocess);
            },
            $captured,
        );

        $this->assertFalse(ExtensionOperationLock::isFree('module', 'sirsoft-page'), '자식 것이라는 근거 없이 잠금을 풀었다');
        $this->assertSame([], $captured, '자식 것이라는 근거 없이 확장 상태를 되돌렸다');
    }

    /**
     * 앞 요청이 자식을 띄운 직후 죽어 자식이 아직 부팅 중이면(표식·정보 파일 없음) 곧바로 「끝남」으로 보지 않고 잠시 기다린다.
     *
     * @scenario case=child_running
     *
     * @effects child_start_grace_waits_before_marker_appears
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_just_spawned_child_without_a_marker_is_waited_for(): void
    {
        $this->interruptedRun(startedAgo: 3);

        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldNotReceive('resolve');

        $this->advance($runners);

        $after = $this->state();
        $this->assertSame(CoreUpdateStep::Bundled->value, $after->nextStep()?->value, '부팅 중인 자식을 끝난 것으로 보고 넘어갔다');
        $this->assertSame(1, (int) $after->context('bundled_progress')['index']);
    }

    /**
     * 표식 잠금이 비었어도 정보가 다른 호스트의 자식이면(여러 웹 노드가 저장소를 공유하는데 잠금은 노드 사이에 공유되지 않음)
     * 죽었다고 단정하지 않는다. 같은 호스트에서 그 PID 가 살아 있어도 단정하지 않는다 — 기다림 상한에 맡긴다.
     *
     * @scenario case=marker_lifecycle
     *
     * @effects other_host_or_live_pid_not_judged_orphan
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_other_host_or_live_pid_is_not_judged_orphan(): void
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'bundled-child_host.lock';

        File::put($path, '');
        File::put(BundledChildMarker::infoPath($path), (string) json_encode(['pid' => self::DEAD_PID, 'host' => 'another-node-'.uniqid()]));
        $this->assertSame(BundledChildMarker::RUNNING, BundledChildMarker::inspect($path)['state'], '다른 호스트의 자식을 고아로 판정했다');

        File::put($path, '');
        File::put(BundledChildMarker::infoPath($path), (string) json_encode(['pid' => getmypid()]));
        $this->assertSame(BundledChildMarker::RUNNING, BundledChildMarker::inspect($path)['state'], '살아 있는 PID 의 자식을 고아로 판정했다');
    }

    /**
     * 자식 명령은 매니페스트의 표식을 잡고 돌다가 끝나면 놓고 치운다 — 표식을 모르는 명령이면 미리 둔 표식이 그대로 남는다.
     *
     * @scenario case=marker_lifecycle
     *
     * @effects child_command_releases_marker_on_exit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_child_command_holds_and_releases_the_manifest_marker(): void
    {
        $marker = $this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'bundled-child_cmd.lock';
        File::put($marker, '');
        File::put(BundledChildMarker::infoPath($marker), (string) json_encode(['pid' => 1]));
        $manifest = $this->root.DIRECTORY_SEPARATOR.'manifest.json';
        File::put($manifest, (string) json_encode([
            'modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => [],
            'rebuild_search_index' => false, 'child_marker' => $marker,
        ]));

        $this->artisan('core:execute-bundled-updates', ['--manifest' => $manifest, '--item-process' => true])->assertExitCode(0);

        $this->assertFileDoesNotExist($marker, '자식 명령이 표식을 치우지 않았다');
        $this->assertSame(BundledChildMarker::FINISHED, BundledChildMarker::inspect($marker)['state'], '자식 명령이 끝난 뒤 「끝남」으로 판정되지 않는다');
    }

    /**
     * 번들 두 항목 중 첫 항목을 돌던 요청이 끊긴 실행 상태를 만든다(종료 처리가 그 항목을 끊긴 항목으로 적고 다음으로 넘긴 상태).
     *
     * @param  int|null  $waitingSince  이미 기다리기 시작한 시각 (상한 시험용)
     * @param  int  $startedAgo  자식을 띄운 지 지난 초
     * @return string 그 항목 자식의 표식 경로
     */
    private function interruptedRun(?int $waitingSince = null, int $startedAgo = 40): string
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [
                ['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep'],
                ['type' => 'template', 'identifier' => 'sirsoft-admin_basic', 'strategy' => 'keep'],
            ],
            'index' => 1, 'success' => 0, 'failed' => 0, 'planned' => 2,
            'interrupted' => [['type' => 'template', 'identifier' => 'sirsoft-basic', 'strategy' => 'keep']],
        ]]);

        $marker = dirname($state->path()).DIRECTORY_SEPARATOR.'bundled-child_test.lock';
        $state->mergeContext(['bundled_child' => array_filter([
            'marker' => $marker,
            'identifier' => 'sirsoft-basic',
            'started_at' => time() - $startedAgo,
            'waiting_since' => $waitingSince,
            'manifest' => $this->manifestPath(),
        ], static fn ($v) => $v !== null)]);
        File::put($this->manifestPath(), '{}');

        return $marker;
    }

    /**
     * 끊긴 요청이 남긴 항목 매니페스트 경로 (생산자 이름 규약).
     *
     * @return string 경로
     */
    private function manifestPath(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'bundled-updates-manifest_test.json';
    }

    /**
     * 모듈 sirsoft-page 한 항목을 지금 요청에서 돌린다 — 러너가 자식 결과를 흉내 낸다. 행은 `updating`(자식이 세운 상태)으로 보인다.
     *
     * @param  \Closure(array<string, mixed>): CommandRunResult  $child  자식 흉내 (인자: 실행 상태의 `bundled_child`)
     * @param  array<string, mixed>  $captured  행에 쓴 칸 값 (참조)
     * @return void 반환값 없음
     */
    private function moduleItemRun(\Closure $child, array &$captured): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $state->mergeContext(['bundled_progress' => [
            'items' => [['type' => 'module', 'identifier' => 'sirsoft-page', 'strategy' => 'keep']],
            'index' => 0, 'success' => 0, 'failed' => 0, 'planned' => 1,
        ]]);

        $status = 'active';
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->with('sirsoft-page')->andReturnUsing(function () use (&$status): Module {
            return (new Module)->forceFill(['identifier' => 'sirsoft-page', 'status' => $status, 'version' => '1.3.5', 'updated_at' => now()]);
        });
        $repository->shouldReceive('updateByIdentifier')->andReturnUsing(function (string $identifier, array $attributes) use (&$captured): int {
            $captured = $attributes;

            return 1;
        });
        $this->app->instance(ModuleRepositoryInterface::class, $repository);
        $journals = Mockery::mock(ExtensionUpdateJournalRepositoryInterface::class);
        $journals->shouldReceive('find')->andReturn(null);
        $journals->shouldReceive('delete');
        $this->app->instance(ExtensionUpdateJournalRepositoryInterface::class, $journals);

        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function () use ($child, &$status): CommandRunResult {
            $status = 'updating';

            return $child((array) $this->state()->context('bundled_child'));
        });
        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldReceive('resolve')->andReturn($runner);

        $this->advance($runners);
    }

    /**
     * 모듈 sirsoft-page 를 돌던 자식이 죽어 고아 표식과 확장 잠금·`updating` 행을 남긴 상태에서 다음 요청을 한 번 돌린다.
     *
     * @param  ExtensionUpdateJournal|null  $journal  그 업데이트의 진행 기록 (null 이면 파일을 바꾸기 전)
     * @return array<string, mixed> 행에 쓴 칸 값
     */
    private function orphanedModuleRun(?ExtensionUpdateJournal $journal): array
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions(updateBundledExtensions: true), '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);
        $item = ['type' => 'module', 'identifier' => 'sirsoft-page', 'strategy' => 'keep'];
        $state->mergeContext(['bundled_progress' => [
            'items' => [$item], 'index' => 1, 'success' => 0, 'failed' => 0, 'planned' => 1, 'interrupted' => [$item],
        ]]);
        $marker = dirname($state->path()).DIRECTORY_SEPARATOR.'bundled-child_orphan.lock';
        File::put($marker, '');
        $lockKey = ExtensionOperationLock::key('module', 'sirsoft-page');
        File::put(BundledChildMarker::infoPath($marker), (string) json_encode(['pid' => self::DEAD_PID, 'locks' => [$lockKey => 'dead-child']]));
        $state->mergeContext(['bundled_child' => [
            'marker' => $marker, 'type' => 'module', 'identifier' => 'sirsoft-page', 'prior_status' => 'active', 'started_at' => time() - 40,
        ]]);

        // 죽은 자식이 쥐었던 확장 작업 잠금 (자식이 표식에 적은 소유자)
        $this->assertTrue(Cache::lock($lockKey, 600, 'dead-child')->get());

        $captured = [];
        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->with('sirsoft-page')->andReturn(
            (new Module)->forceFill(['identifier' => 'sirsoft-page', 'status' => 'updating', 'version' => $journal !== null ? '1.3.6' : '1.3.5', 'updated_at' => now()])
        );
        $repository->shouldReceive('updateByIdentifier')->andReturnUsing(function (string $identifier, array $attributes) use (&$captured): int {
            $captured = $attributes;

            return 1;
        });
        $this->app->instance(ModuleRepositoryInterface::class, $repository);
        $journals = Mockery::mock(ExtensionUpdateJournalRepositoryInterface::class);
        $journals->shouldReceive('find')->andReturn($journal);
        $journals->shouldReceive('delete');
        $this->app->instance(ExtensionUpdateJournalRepositoryInterface::class, $journals);

        $this->advance($this->runnerReturningSuccess());

        return $captured;
    }

    /**
     * 「살아 있는 자식」처럼 표식을 잠그고 정보 파일에 내용을 적는다.
     *
     * @param  string  $marker  표식 경로
     * @param  array<string, mixed>  $payload  표식 내용
     * @return void 반환값 없음
     */
    private function holdMarker(string $marker, array $payload): void
    {
        $this->held = fopen($marker, 'c+');
        $this->assertTrue(flock($this->held, LOCK_EX | LOCK_NB));
        // 잠근 파일은 Windows 에서 다른 핸들이 읽지 못한다 — 내용(PID)은 옆 정보 파일에 둔다.
        File::put(BundledChildMarker::infoPath($marker), (string) json_encode($payload));
    }

    /**
     * 항목 실행을 성공으로 돌려주는 러너.
     *
     * @return CommandRunnerResolver 러너 해석기
     */
    private function runnerReturningSuccess(): CommandRunnerResolver
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->andReturn(new CommandRunResult(0, '[BUNDLED-RESULT] {"success":1,"failed":0}', ExecutionMode::Subprocess));
        $runners = Mockery::mock(CommandRunnerResolver::class);
        $runners->shouldReceive('resolve')->andReturn($runner);

        return $runners;
    }

    /**
     * 다음 요청 한 번. 시계는 단계 시작 뒤 곧바로 몫을 다 쓴 것으로 답한다(요청 안에서 기다리지 않게).
     *
     * @param  CommandRunnerResolver  $runners  러너 해석기
     * @return void 반환값 없음
     */
    private function advance(CommandRunnerResolver $runners): void
    {
        $calls = 0;
        $clock = static function () use (&$calls): float {
            return $calls++ === 0 ? 1000.0 : 1_000_000.0;
        };
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn(['modules' => [], 'plugins' => [], 'templates' => [
            ['identifier' => 'sirsoft-basic', 'current_version' => '1.0.0', 'latest_version' => '1.0.1'],
        ]]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        (new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), $runners, new CoreUpdateTimeBudget($clock, 300)))->advance();
    }

    /**
     * 격리 경로의 실행 상태.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
    }
}
