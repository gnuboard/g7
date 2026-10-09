<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\UserMileageCleanupListener;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserMileageCleanupListener 테스트 (회원 탈퇴·삭제 시 마일리지 원장 보존 — E3)
 *
 * 예전에는 탈퇴·삭제 때 원장·잔액 캐시 행을 지워 지급·사용·소멸 경위가 사라졌다. 이제 탈퇴는 남은 마일리지를
 * 「탈퇴로 소멸」 거래로 기록하고 행을 보존하며, 삭제는 소멸 기록 뒤 회원 연결만 끊는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/withdrawn-member-mileage-ledger.yaml`
 */
class UserMileageCleanupListenerTest extends ModuleTestCase
{
    private UserMileageCleanupListener $listener;

    /**
     * 탈퇴·삭제 정리 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(UserMileageCleanupListener::class);
    }

    /**
     * 회원과 활성 lot/캐시를 생성합니다.
     *
     * @return array{0: User, 1: MileageTransaction} [회원, lot]
     */
    private function seedUserWithBalance(): array
    {
        $user = User::factory()->create();
        $lot = MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 5000,
            'remaining_amount' => 5000,
            'balance_after' => 5000,
            'expires_at' => Carbon::now()->addYear(),
        ]);
        app(MileageBalanceRepositoryInterface::class)->recalculateForUser($user->id);

        return [$user, $lot];
    }

    /**
     * 탈퇴와 삭제는 서로 다른 처리기로 구독한다 — 탈퇴는 보존, 삭제는 연결만 끊는다.
     *
     * @effects withdrawal_and_deletion_have_separate_handlers
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_to_withdraw_and_delete_hooks(): void
    {
        $hooks = UserMileageCleanupListener::getSubscribedHooks();

        $this->assertSame('handleUserWithdraw', $hooks['core.user.before_withdraw']['method']);
        $this->assertSame('handleUserDelete', $hooks['core.user.before_delete']['method']);
    }

    /**
     * 탈퇴하면 남은 마일리지를 소멸 거래로 기록하고 거래 행·잔액 캐시는 지우지 않는다.
     *
     * @effects withdrawal_forfeits_balance_and_keeps_ledger
     *
     * @return void 반환값 없음
     */
    public function test_withdrawal_forfeits_the_balance_but_keeps_the_ledger(): void
    {
        [$user, $lot] = $this->seedUserWithBalance();

        $this->listener->handleUserWithdraw($user);

        $lot->refresh();
        $this->assertSame(0.0, (float) $lot->remaining_amount);
        $this->assertNotNull($lot->expired_at);

        $expired = MileageTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->get();
        $this->assertCount(1, $expired);
        $this->assertEquals(-5000, (float) $expired[0]->amount);
        $this->assertSame($lot->id, (int) $expired[0]->source_transaction_id);
        $this->assertSame('withdrawal', $expired[0]->metadata['reason'] ?? null);
        $this->assertEquals(0, (float) $expired[0]->balance_after);

        $balance = MileageBalance::query()->where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertNotNull($balance);
        $this->assertEquals(0, (float) $balance->available);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'mileage.expire',
            'loggable_id' => $expired[0]->id,
        ]);
    }

    /**
     * 탈퇴 처리를 두 번 불러도 소멸 거래는 한 번만 생긴다.
     *
     * @effects withdrawal_forfeits_balance_and_keeps_ledger
     *
     * @return void 반환값 없음
     */
    public function test_withdrawal_is_idempotent(): void
    {
        [$user] = $this->seedUserWithBalance();

        $this->listener->handleUserWithdraw($user);
        $this->listener->handleUserWithdraw($user);

        $this->assertSame(1, MileageTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->count());
    }

    /**
     * 회원을 완전 삭제하면 남은 마일리지를 소멸 기록하고 거래 행의 회원 연결만 끊는다.
     *
     * @effects deletion_detaches_user_and_keeps_ledger
     *
     * @return void 반환값 없음
     */
    public function test_hard_delete_detaches_the_user_and_keeps_the_ledger(): void
    {
        [$user, $lot] = $this->seedUserWithBalance();

        $this->listener->handleUserDelete($user);

        $this->assertNull(MileageTransaction::query()->whereKey($lot->id)->value('user_id'));
        $this->assertTrue(MileageTransaction::query()
            ->whereNull('user_id')
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->where('source_transaction_id', $lot->id)
            ->where('metadata->reason', 'deletion')
            ->exists());
        $this->assertFalse(MileageBalance::query()->where('user_id', $user->id)->exists());
    }

    /**
     * 리스너 없이 회원 행을 지워도 외래키(nullOnDelete)가 거래 행을 남긴다 — 안전망.
     *
     * @effects deletion_detaches_user_and_keeps_ledger
     *
     * @return void 반환값 없음
     */
    public function test_deleting_the_user_row_nulls_the_ledger_user_id(): void
    {
        [$user, $lot] = $this->seedUserWithBalance();
        MileageBalance::query()->where('user_id', $user->id)->delete();

        $user->delete();

        $this->assertTrue(MileageTransaction::query()->whereKey($lot->id)->exists());
        $this->assertNull(MileageTransaction::query()->whereKey($lot->id)->value('user_id'));
    }

    /**
     * 연결이 끊긴 행이 있어도 잔액 전량 재산출(스케줄 reconcile)이 죽지 않는다.
     *
     * @effects detached_rows_are_skipped_by_balance_reconcile
     *
     * @return void 반환값 없음
     */
    public function test_reconcile_skips_detached_rows(): void
    {
        [$user] = $this->seedUserWithBalance();
        $this->listener->handleUserDelete($user);

        app(MileageBalanceRepositoryInterface::class)->recalculateAll();

        $this->assertFalse(MileageBalance::query()->whereNull('user_id')->exists());
    }

    /**
     * 마일리지가 없는 회원은 탈퇴·삭제 처리가 아무것도 만들지 않는다.
     *
     * @effects withdrawal_forfeits_balance_and_keeps_ledger
     *
     * @return void 반환값 없음
     */
    public function test_is_idempotent_for_user_without_mileage(): void
    {
        $user = User::factory()->create();

        $this->listener->handleUserWithdraw($user);
        $this->listener->handleUserDelete($user);

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('ecommerce_mileage_balances', ['user_id' => $user->id]);
    }
}
