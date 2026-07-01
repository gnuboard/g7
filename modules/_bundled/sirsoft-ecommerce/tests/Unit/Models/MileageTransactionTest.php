<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageTransaction 모델 테스트 (casts / 관계 / 스코프)
 */
class MileageTransactionTest extends ModuleTestCase
{
    /**
     * casts: type=Enum, 금액=decimal, 시각=datetime, metadata=array.
     */
    public function test_casts(): void
    {
        $user = User::factory()->create();
        $tx = MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 1000,
            'remaining_amount' => 1000,
            'balance_after' => 1000,
            'expires_at' => now()->addDays(10),
            'metadata' => ['shortfall' => 50],
        ])->fresh();

        $this->assertInstanceOf(MileageTransactionTypeEnum::class, $tx->type);
        $this->assertInstanceOf(Carbon::class, $tx->expires_at);
        $this->assertIsArray($tx->metadata);
        $this->assertSame(50, $tx->metadata['shortfall']);
    }

    /**
     * user 관계.
     */
    public function test_user_relation(): void
    {
        $user = User::factory()->create();
        $tx = MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn',
            'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100,
        ]);

        $this->assertTrue($tx->user->is($user));
    }

    /**
     * grantedByUser 관계 (관리자 부여).
     */
    public function test_granted_by_user_relation(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $tx = MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => 'admin_earn',
            'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100, 'granted_by' => $admin->id,
        ]);

        $this->assertTrue($tx->grantedByUser->is($admin));
    }

    /**
     * active 스코프: 잔여>0 + 미소멸 + 미만료.
     */
    public function test_active_scope(): void
    {
        $user = User::factory()->create();
        // 활성
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn', 'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100]);
        // 만료
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn', 'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100, 'expires_at' => now()->subDay()]);
        // 잔여 0
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'order_use', 'amount' => -100, 'remaining_amount' => 0, 'balance_after' => 0]);
        // 소멸 처리됨
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn', 'amount' => 100, 'remaining_amount' => 0, 'balance_after' => 0, 'expired_at' => now()]);

        $this->assertSame(1, MileageTransaction::query()->where('user_id', $user->id)->active()->count());
    }

    /**
     * forUserCurrency 스코프.
     */
    public function test_for_user_currency_scope(): void
    {
        $user = User::factory()->create();
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn', 'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100]);
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'USD', 'type' => 'purchase_earn', 'amount' => 1, 'remaining_amount' => 1, 'balance_after' => 1]);

        $this->assertSame(1, MileageTransaction::query()->forUserCurrency($user->id, 'KRW')->count());
    }

    /**
     * expiringBefore 스코프.
     */
    public function test_expiring_before_scope(): void
    {
        $user = User::factory()->create();
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn', 'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100, 'expires_at' => now()->addDays(3)]);
        MileageTransaction::create(['user_id' => $user->id, 'currency' => 'KRW', 'type' => 'purchase_earn', 'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100, 'expires_at' => now()->addDays(30)]);

        $this->assertSame(1, MileageTransaction::query()->expiringBefore(now()->addDays(7))->count());
    }

    /**
     * order 관계.
     */
    public function test_order_relation(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $tx = MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => 'order_use',
            'amount' => -100, 'remaining_amount' => 0, 'balance_after' => 0, 'order_id' => $order->id,
        ]);

        $this->assertTrue($tx->order->is($order));
    }
}
