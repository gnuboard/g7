<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\StuckUpdateRecovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 멈춘 업데이트(`updating` 고착) 정리 판정 테스트 (7.1.1 출시 전)
 *
 * 업데이트 자식 프로세스가 도중에 죽으면 행이 `updating` 으로 남고, 상태 가드가 시간 조건 없이 막아 업데이트·활성화로
 * 빠져나올 수 없었다(사이트 503). 업데이트 잠금이 비어 있고 잠금 유지 시간(600초)보다 오래된 `updating` 만 고착으로 보고
 * `inactive` 로 되돌린다 — 직전 상태를 남기는 기록이 업데이트 흐름에 없어서다(결정: 새 컬럼 없이, 없으면 inactive).
 */
class StuckUpdateRecoveryTest extends TestCase
{
    /**
     * 테스트 준비 — 시각을 고정하고 잠금을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-18 10:00:00');
        Cache::lock(ExtensionOperationLock::key('module', 'g7test-stuck'))->forceRelease();
        StuckUpdateRecovery::takeRecovered();
    }

    /**
     * 시각 고정을 푼다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::lock(ExtensionOperationLock::key('module', 'g7test-stuck'))->forceRelease();

        parent::tearDown();
    }

    /**
     * 판정용 행을 만듭니다.
     *
     * @param  string  $status  상태
     * @param  int  $secondsAgo  마지막 갱신이 몇 초 전인지
     * @return object 행
     */
    private function record(string $status, int $secondsAgo): object
    {
        return (object) ['status' => $status, 'updated_at' => Carbon::now()->subSeconds($secondsAgo)];
    }

    /**
     * 잠금이 비어 있고 600초 넘게 지난 updating 은 inactive 로 되돌리고 경고를 남긴다.
     *
     * @scenario entry_point=recovery_judgement
     *
     * @effects stale_updating_with_free_lock_reset_to_inactive, recovery_logs_warning, recovery_recorded_for_command_output, lock_released_after_recovery_judgement
     *
     * @return void 반환값 없음
     */
    public function test_stale_updating_is_reset_to_inactive_with_warning(): void
    {
        Log::spy();
        $written = null;

        $restored = StuckUpdateRecovery::recover('module', 'g7test-stuck', $this->record(ExtensionStatus::Updating->value, 601), function (array $attributes) use (&$written): void {
            $written = $attributes;
        });

        $this->assertSame(ExtensionStatus::Inactive->value, $restored);
        $this->assertSame(ExtensionStatus::Inactive->value, $written['status'] ?? null);
        $this->assertSame([['type' => 'module', 'identifier' => 'g7test-stuck', 'status' => 'inactive', 'unfinished' => null]], StuckUpdateRecovery::takeRecovered(), '명령 출력용 기록(진행 기록 없음 = 판 전환 끝남)');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '멈춘')
            && ($context['identifier'] ?? null) === 'g7test-stuck'
            && ($context['restored_status'] ?? null) === 'inactive')->once();

        // 잠금은 판정 뒤 풀려 있어야 한다 — 이어지는 업데이트·활성화가 잠금을 다시 잡는다.
        $this->assertTrue(Cache::lock(ExtensionOperationLock::key('module', 'g7test-stuck'), 5)->get());
    }

    /**
     * 아직 600초가 안 됐거나, 다른 프로세스가 업데이트 잠금을 들고 있거나, updating 이 아니면 건드리지 않는다.
     *
     * @scenario entry_point=recovery_judgement
     *
     * @effects recent_updating_left_alone, updating_with_lock_held_by_other_process_left_alone, non_updating_status_left_alone
     *
     * @return void 반환값 없음
     */
    public function test_running_or_recent_updates_are_left_alone(): void
    {
        $called = false;
        $updater = function () use (&$called): void {
            $called = true;
        };

        $this->assertNull(StuckUpdateRecovery::recover('module', 'g7test-stuck', $this->record(ExtensionStatus::Updating->value, 599), $updater));
        $this->assertNull(StuckUpdateRecovery::recover('module', 'g7test-stuck', $this->record(ExtensionStatus::Active->value, 9999), $updater));
        $this->assertNull(StuckUpdateRecovery::recover('module', 'g7test-stuck', $this->record(ExtensionStatus::Installing->value, 9999), $updater));

        $held = Cache::lock(ExtensionOperationLock::key('module', 'g7test-stuck'), 600);
        $this->assertTrue($held->get());
        $this->assertNull(StuckUpdateRecovery::recover('module', 'g7test-stuck', $this->record(ExtensionStatus::Updating->value, 9999), $updater), '다른 프로세스가 업데이트 중');
        $held->release();

        $this->assertFalse($called);
    }

    /**
     * 호출한 쪽이 이미 업데이트 잠금을 잡았으면(업데이트 진입점) 잠금 확인 없이 판정한다.
     *
     * @scenario entry_point=recovery_judgement
     *
     * @effects caller_holding_lock_can_recover
     *
     * @return void 반환값 없음
     */
    public function test_caller_holding_the_lock_can_recover(): void
    {
        $held = ExtensionOperationLock::acquire('module', 'g7test-stuck');

        $restored = StuckUpdateRecovery::recover('module', 'g7test-stuck', $this->record(ExtensionStatus::Updating->value, 601), fn () => null, lockHeld: true);

        $this->assertSame(ExtensionStatus::Inactive->value, $restored);
        $held->release();
    }

    /**
     * 정리하지 않을 때 상태 가드 문구가 멈춘 업데이트의 해결 방법(--force·활성화)을 알려 준다.
     *
     * @scenario entry_point=status_guard
     *
     * @effects stale_updating_guard_message_points_to_force, running_update_guard_message_has_no_force_hint
     *
     * @return void 반환값 없음
     */
    public function test_guard_message_points_to_force_for_stale_updating(): void
    {
        app()->setLocale('ko');

        try {
            StuckUpdateRecovery::assertNotInProgress('g7test-stuck', $this->record(ExtensionStatus::Updating->value, 601));
            $this->fail('진행 중 상태인데 막지 않았다');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }

        try {
            StuckUpdateRecovery::assertNotInProgress('g7test-stuck', $this->record(ExtensionStatus::Updating->value, 10));
            $this->fail('진행 중 상태인데 막지 않았다');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('--force', $e->getMessage(), '실제로 도는 업데이트에는 종전 문구');
        }
    }
}
