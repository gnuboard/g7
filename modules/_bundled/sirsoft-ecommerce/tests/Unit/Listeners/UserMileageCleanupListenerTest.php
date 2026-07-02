<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\UserMileageCleanupListener;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserMileageCleanupListener 테스트 (회원 탈퇴/삭제 시 마일리지 정리 — 정책 확정 #8)
 */
class UserMileageCleanupListenerTest extends ModuleTestCase
{
    private UserMileageCleanupListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(UserMileageCleanupListener::class);
    }

    /**
     * 회원과 활성 lot/캐시를 생성합니다.
     *
     * @return User 생성된 회원
     */
    private function seedUserWithBalance(): User
    {
        $user = User::factory()->create();
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 5000,
            'remaining_amount' => 5000,
            'balance_after' => 5000,
            'expires_at' => Carbon::now()->addYear(),
        ]);
        app(MileageBalanceRepositoryInterface::class)->recalculateForUser($user->id);

        return $user;
    }

    public function test_subscribes_to_withdraw_and_delete_hooks(): void
    {
        $hooks = UserMileageCleanupListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.before_withdraw', $hooks);
        $this->assertArrayHasKey('core.user.before_delete', $hooks);
    }

    public function test_expires_active_lots_with_activity_log_then_removes_rows(): void
    {
        $user = $this->seedUserWithBalance();

        $this->assertDatabaseHas('ecommerce_mileage_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('ecommerce_mileage_balances', ['user_id' => $user->id]);

        $this->listener->handleUserRemoval($user);

        // 거래·캐시 행 삭제 확인 (CASCADE 비의존 명시 삭제)
        $this->assertDatabaseMissing('ecommerce_mileage_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('ecommerce_mileage_balances', ['user_id' => $user->id]);

        // 소멸 기록이 활동로그에 잔존 (감사 흐름 — 거래 행은 회원과 함께 소멸)
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'mileage.expire',
        ]);
    }

    public function test_is_idempotent_for_user_without_mileage(): void
    {
        $user = User::factory()->create();

        $this->listener->handleUserRemoval($user);

        $this->assertDatabaseMissing('ecommerce_mileage_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('ecommerce_mileage_balances', ['user_id' => $user->id]);
    }
}
