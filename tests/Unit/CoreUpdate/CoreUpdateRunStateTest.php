<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 요청 경계를 넘는 진행 상태.
 *
 * 한 요청이 한 단계만 수행하므로 이 파일이 없으면 **다음 요청이 어디까지 갔는지 알
 * 방법이 없다**. 그래서 재개·점유·회수 셋을 계약으로 잠근다.
 *
 * 커버 축: core_update_web run_state·claim·apply_list
 */
class CoreUpdateRunStateTest extends TestCase
{
    private string $directory;

    private string $path;

    /**
     * 테스트 전용 상태 디렉토리를 만들고 남아 있던 웹 실행 상태 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core-update-state');
        File::ensureDirectoryExists($this->directory);
        $this->path = $this->directory.DIRECTORY_SEPARATOR.'web-run.json';

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * 테스트 전용 상태 디렉토리를 통째로 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * 새 상태를 만듭니다.
     *
     * @return CoreUpdateRunState 격리된 경로를 쓰는 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->path);
    }

    /**
     * 착수 → 진행 → 완료의 재개 지점이 파일에 남는지 확인합니다.
     *
     * @scenario run_state=running, concurrency=single
     *
     * @effects progress_survives_a_new_state_instance
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_progress_survives_a_new_state_instance(): void
    {
        $state = $this->state();
        $runId = $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 7);

        $this->assertNotSame('', $runId);
        $this->assertSame(CoreUpdateStep::Preflight, $state->nextStep());

        $state->patch([
            'completed_steps' => [CoreUpdateStep::Preflight->value],
            'next_step' => CoreUpdateStep::Maintenance->value,
        ]);
        $state->mergeContext(['pending_path' => '/tmp/core_20260831_120000']);
        $state->appendLog('사전 점검 통과');

        // 다른 요청이 읽는 상황을 재현한다 — 인스턴스 캐시가 아니라 파일이 근거여야 한다.
        $reloaded = $this->state();

        $this->assertSame($runId, $reloaded->runId());
        $this->assertSame(CoreUpdateStep::Maintenance, $reloaded->nextStep());
        $this->assertSame([CoreUpdateStep::Preflight->value], $reloaded->completedSteps());
        $this->assertSame('/tmp/core_20260831_120000', $reloaded->context('pending_path'));
        $this->assertCount(1, $reloaded->logs());
        $this->assertStringContainsString('사전 점검 통과', $reloaded->logs()[0]);
        $this->assertTrue($reloaded->isActive());
    }

    /**
     * 착수 선택이 그대로 복원되는지 확인합니다 (다음 요청이 같은 선택으로 진행).
     *
     * @scenario run_state=running, source=manual_zip, bundled_extensions=off, rebuild_search_index=on, layout_strategy=keep
     *
     * @effects options_round_trip_through_the_state_file
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_options_round_trip_through_the_state_file(): void
    {
        $this->state()->start(
            new CoreUpdateOptions(
                updateBundledExtensions: false,
                rebuildSearchIndex: true,
                layoutStrategy: CoreUpdateOptions::LAYOUT_KEEP,
                zipFile: 'g7-7.1.0.zip',
            ),
            '7.0.8',
            null,
            1,
        );

        $options = $this->state()->options();

        $this->assertFalse($options->updateBundledExtensions);
        $this->assertTrue($options->rebuildSearchIndex);
        $this->assertSame(CoreUpdateOptions::LAYOUT_KEEP, $options->layoutStrategy);
        $this->assertSame('g7-7.1.0.zip', $options->zipFile);
        $this->assertTrue($options->usesManualZip());
    }

    /**
     * 다른 요청이 점유 중이면 두 번째 요청이 같은 단계를 돌리지 못하는지 확인합니다.
     *
     * 더블클릭으로 뜬 두 요청이 서로 다른 단계를 동시에 돌리면 백업과 적용이 뒤엉킨다.
     * `flock` 은 프로세스 수명에 묶여 요청 사이를 잇지 못하므로 이 점유가 유일한 방어다.
     *
     * @scenario run_state=running, concurrency=duplicate_request
     *
     * @effects a_second_request_cannot_take_a_live_claim
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_second_request_cannot_take_a_live_claim(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);

        $this->assertTrue($state->claim('token-a'));

        $other = $this->state();
        $this->assertFalse($other->claim('token-b'), '심박이 살아 있는 점유를 빼앗으면 안 된다.');

        // 같은 토큰의 재획득은 정상 경로다 (브라우저의 순차 호출).
        $this->assertTrue($this->state()->claim('token-a'));

        $state->releaseClaim('token-a');
        $this->assertTrue($this->state()->claim('token-b'), '놓은 점유는 다른 요청이 가져갈 수 있어야 한다.');
    }

    /**
     * 심박이 끊긴 지 오래된 점유는 회수되는지 확인합니다 (비정상 종료 복구).
     *
     * @scenario run_state=running, concurrency=stale_claim
     *
     * @effects a_stale_claim_is_reclaimed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_stale_claim_is_reclaimed(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);
        $state->claim('token-dead');

        $raw = json_decode((string) file_get_contents($this->path), true);
        $raw['claim']['heartbeat_at'] = now()
            ->subSeconds(CoreUpdateRunState::CLAIM_STALE_SECONDS + 60)
            ->toIso8601String();
        file_put_contents($this->path, json_encode($raw));

        $this->assertTrue($this->state()->claim('token-new'), '끊긴 점유는 회수돼야 재개가 가능하다.');
    }

    /**
     * 심박 시각을 읽을 수 없으면 **보유 중으로 본다** (fail-closed).
     *
     * 판정 불능을 「오래됨」으로 접으면 진행 중인 다른 요청의 점유를 빼앗는다 — 그것이
     * 정확히 이 점유가 막으려던 상태다.
     *
     * @scenario run_state=running, concurrency=stale_claim
     *
     * @effects an_unreadable_heartbeat_is_treated_as_held
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_unreadable_heartbeat_is_treated_as_held(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);
        $state->claim('token-a');

        $raw = json_decode((string) file_get_contents($this->path), true);
        $raw['claim']['heartbeat_at'] = 'not-a-time';
        file_put_contents($this->path, json_encode($raw));

        $this->assertFalse($this->state()->claim('token-b'));
    }

    /**
     * 증분 적용 목록이 사이드카 파일로 오가는지 확인합니다.
     *
     * @scenario run_state=running, concurrency=single
     *
     * @effects apply_list_round_trips_through_a_sidecar_file
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_apply_list_round_trips_through_a_sidecar_file(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);

        $this->assertNull($state->loadApplyList(), '아직 산출하지 않았으면 null 이어야 전체 덮어쓰기로 회귀한다.');

        $state->storeApplyList(['app/Foo.php', 'config/app.php']);
        $this->assertSame(['app/Foo.php', 'config/app.php'], $this->state()->loadApplyList());

        $state->storeApplyList(null);
        $this->assertNull($this->state()->loadApplyList());
    }

    /**
     * 기록 정리가 상태와 사이드카를 함께 걷는지 확인합니다.
     *
     * @scenario run_state=finished, concurrency=single
     *
     * @effects clear_removes_both_the_state_and_the_sidecar
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_clear_removes_both_the_state_and_the_sidecar(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);
        $state->storeApplyList(['app/Foo.php']);

        $applyListPath = $state->applyListPath();
        $this->assertFileExists($this->path);
        $this->assertFileExists($applyListPath);

        $state->clear();

        $this->assertFileDoesNotExist($this->path);
        $this->assertFileDoesNotExist($applyListPath, '사이드카를 남기면 다음 실행이 남의 적용 목록을 읽는다.');
        $this->assertFalse($this->state()->exists());
    }

    /**
     * 새 실행이 이전 실행의 사이드카를 승계하지 않는지 확인합니다.
     *
     * @scenario run_state=running, concurrency=single
     *
     * @effects a_new_run_does_not_inherit_the_previous_apply_list
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_new_run_does_not_inherit_the_previous_apply_list(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);
        $state->storeApplyList(['app/Old.php']);

        $state->start(new CoreUpdateOptions, '7.1.0', '7.1.1', 1);

        $this->assertNull(
            $this->state()->loadApplyList(),
            '이전 실행의 적용 목록이 남으면 새 실행이 엉뚱한 파일만 덮어쓴다.',
        );
    }

    /**
     * 진행 기록이 상한을 넘으면 오래된 줄부터 버려지는지 확인합니다.
     *
     * @scenario run_state=running, concurrency=single
     *
     * @effects logs_are_capped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_logs_are_capped(): void
    {
        $state = $this->state();
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);

        for ($i = 0; $i < CoreUpdateRunState::MAX_LOG_LINES + 25; $i++) {
            $state->appendLog('line '.$i);
        }

        $logs = $state->logs();

        $this->assertCount(CoreUpdateRunState::MAX_LOG_LINES, $logs);
        $this->assertStringContainsString('line '.(CoreUpdateRunState::MAX_LOG_LINES + 24), $logs[count($logs) - 1]);
    }
}
