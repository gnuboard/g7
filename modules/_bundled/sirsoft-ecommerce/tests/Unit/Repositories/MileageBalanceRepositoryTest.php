<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageBalanceRepository 테스트 (§18.2-C·J — 캐시=원장/옵션 정합)
 */
class MileageBalanceRepositoryTest extends ModuleTestCase
{
    private MileageBalanceRepositoryInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = app(MileageBalanceRepositoryInterface::class);
    }

    private function lot(int $userId, float $amount, $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => $amount, 'remaining_amount' => $amount, 'balance_after' => $amount, 'expires_at' => $expiresAt,
        ]);
    }

    /**
     * recalculateForUser: available 이 원장 활성 lot SUM 과 일치.
     */
    public function test_recalculate_for_user_matches_ledger_sum(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 1000);
        $this->lot($user->id, 500, now()->subDay()); // 만료 → 제외
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ORDER_USE->value,
            'amount' => -200, 'remaining_amount' => 0, 'balance_after' => 800,
        ]);

        $this->cache->recalculateForUser($user->id, 'KRW');

        $row = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertSame(1000.0, (float) $row->available); // 활성 lot SUM
        $this->assertSame(200.0, (float) $row->total_used);  // 사용 누적
    }

    /**
     * recalculatePending: 원장이 아닌 order_options(미취소·earn ledger 부재) 합에서 파생.
     */
    public function test_recalculate_pending_derives_from_order_options(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        // pending 대상: 미취소 + earn ledger 부재 + 적립액>0
        OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED, 'subtotal_earned_points_amount' => 300,
        ]);
        // 제외: 취소
        OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CANCELLED, 'subtotal_earned_points_amount' => 999,
        ]);
        // 제외: earn ledger 존재
        $earned = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED, 'subtotal_earned_points_amount' => 500,
        ]);
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 500, 'remaining_amount' => 500, 'balance_after' => 500, 'order_option_id' => $earned->id,
        ]);

        $this->cache->recalculatePending($user->id, 'KRW');

        $row = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertSame(300.0, (float) $row->pending);
    }

    /**
     * recalculateAll: 임의 오염 캐시를 원장 기준으로 자기 치유 (drift 교정).
     */
    public function test_recalculate_all_heals_drift(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 700);
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 99999]); // 오염

        $this->cache->recalculateAll();

        $row = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertSame(700.0, (float) $row->available);
    }

    /**
     * recalculateExpiringWindow: N일 내 만료 lot 합/최근일 갱신.
     */
    public function test_recalculate_expiring_window(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 400, now()->addDays(3));   // 윈도우 내
        $this->lot($user->id, 600, now()->addDays(30));  // 윈도우 밖
        $this->cache->recalculateForUser($user->id, 'KRW');

        $this->cache->recalculateExpiringWindow(7);

        $row = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertSame(400.0, (float) $row->expiring_soon);
        $this->assertNotNull($row->expiring_date);
    }

    /**
     * getCachedBalance: 통화별 + 전체 합산 + by_currency.
     */
    public function test_get_cached_balance_aggregates_currencies(): void
    {
        $user = User::factory()->create();
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 1000, 'pending' => 100]);
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'USD', 'available' => 5, 'pending' => 0]);

        $all = $this->cache->getCachedBalance($user->id);

        $this->assertSame(1005.0, (float) $all['available']);
        $this->assertSame(100.0, (float) $all['pending']);
        $this->assertArrayHasKey('KRW', $all['by_currency']);
        $this->assertArrayHasKey('USD', $all['by_currency']);
    }

    /**
     * getExpiringTargets: 소멸 예정 보유 회원만.
     */
    public function test_get_expiring_targets(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        MileageBalance::create(['user_id' => $user1->id, 'currency' => 'KRW', 'available' => 1000, 'expiring_soon' => 200, 'expiring_date' => now()->addDays(3)]);
        MileageBalance::create(['user_id' => $user2->id, 'currency' => 'KRW', 'available' => 1000, 'expiring_soon' => 0]);

        $targets = $this->cache->getExpiringTargets();

        $this->assertSame(1, $targets->count());
        $this->assertSame($user1->id, $targets->first()->user_id);
    }
}
