<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageTransactionRepository 단위 테스트 (§18.2-C)
 */
class MileageTransactionRepositoryTest extends ModuleTestCase
{
    private MileageTransactionRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(MileageTransactionRepositoryInterface::class);
    }

    private function lot(int $userId, float $amount, ?string $currency = 'KRW', $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId, 'currency' => $currency, 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => $amount, 'remaining_amount' => $amount, 'balance_after' => $amount, 'expires_at' => $expiresAt,
        ]);
    }

    public function test_get_balance_sums_active_lots(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 1000);
        $this->lot($user->id, 500, 'KRW', now()->subDay()); // 만료 제외

        $this->assertSame(1000.0, $this->repo->getBalance($user->id));
        $this->assertSame(1000.0, $this->repo->getBalanceByCurrency($user->id, 'KRW'));
    }

    public function test_get_active_lots_for_update_orders_by_expiry_then_id(): void
    {
        $user = User::factory()->create();
        $late = $this->lot($user->id, 100, 'KRW', now()->addDays(100));
        $early = $this->lot($user->id, 100, 'KRW', now()->addDays(10));
        $noExpiry = $this->lot($user->id, 100, 'KRW', null);

        $lots = $this->repo->getActiveLotsForUpdate($user->id, 'KRW');

        // expires_at ASC (NULL 마지막), id ASC — early → late → noExpiry
        $this->assertSame([$early->id, $late->id, $noExpiry->id], $lots->pluck('id')->all());
    }

    public function test_exists_earn_for_option(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $option = OrderOption::factory()->forOrder($order)->create();

        $this->assertFalse($this->repo->existsEarnForOption($option->id));

        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100, 'order_option_id' => $option->id,
        ]);

        $this->assertTrue($this->repo->existsEarnForOption($option->id));
    }

    /**
     * uuid → 정수 user_id 해석 (관리자 수동 액션의 회원 식별 — 코어 UserResource 가 id 미노출).
     */
    public function test_resolve_user_id_by_uuid(): void
    {
        $user = User::factory()->create();

        $this->assertSame($user->id, $this->repo->resolveUserIdByUuid($user->uuid));
        $this->assertNull($this->repo->resolveUserIdByUuid('00000000-0000-0000-0000-000000000000'));
    }

    public function test_exists_restore_for_cancel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->repo->existsRestoreForCancel(999));

        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value,
            'amount' => 100, 'remaining_amount' => 100, 'balance_after' => 100, 'order_cancel_id' => 999,
        ]);

        $this->assertTrue($this->repo->existsRestoreForCancel(999));
    }

    public function test_decrement_remaining(): void
    {
        $user = User::factory()->create();
        $lot = $this->lot($user->id, 1000);

        $this->repo->decrementRemaining($lot, 300);

        $this->assertSame(700.0, (float) $lot->fresh()->remaining_amount);
    }

    public function test_get_earnable_options_filters_by_trigger_delay_and_ledger_absence(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);

        // 적립 대상: confirmed + 지연 경과 + earn ledger 부재 + 적립액>0
        $target = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()->subDays(2), 'subtotal_earned_points_amount' => 500,
        ]);
        // 제외: 적립액 0
        OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()->subDays(2), 'subtotal_earned_points_amount' => 0,
        ]);
        // 제외: earn ledger 존재
        $earned = OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()->subDays(2), 'subtotal_earned_points_amount' => 500,
        ]);
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 500, 'remaining_amount' => 500, 'balance_after' => 500, 'order_option_id' => $earned->id,
        ]);

        $targets = $this->repo->getEarnableOptions('confirmed_at', 'confirmed', 1, now());

        $this->assertSame([$target->id], $targets->pluck('option_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_get_linked_transactions(): void
    {
        $user = User::factory()->create();
        $earn = $this->lot($user->id, 1000);
        $use = MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ORDER_USE->value,
            'amount' => -300, 'remaining_amount' => 0, 'balance_after' => 700, 'source_transaction_id' => $earn->id,
        ]);

        // earn 기준 → 자신을 소비한 use 거래 연결
        $linked = $this->repo->getLinkedTransactions($earn);
        $this->assertTrue($linked->contains('id', $use->id));
    }

    /**
     * 거래유형 필터는 UI 4분류 슬러그(earn/use/expire/adjust)를 받아 8종 enum 으로 역매핑한다.
     */
    public function test_paginate_with_filters_by_type_category(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 1000); // purchase_earn → 'earn'
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 200, 'remaining_amount' => 200, 'balance_after' => 1200, // admin_earn → 'adjust'
        ]);

        // 'adjust' 슬러그는 admin_earn 등 조정계만 매칭 (purchase_earn 제외)
        $this->assertSame(1, $this->repo->paginateWithFilters(['type' => 'adjust'], 20)->total());

        // 'earn' 슬러그는 purchase_earn 만 매칭 (admin_earn 은 adjust)
        $this->assertSame(1, $this->repo->paginateWithFilters(['type' => 'earn'], 20)->total());
    }

    /**
     * 검색 — search_field 별 분기 (member_id 직접 / email·order 관계).
     */
    public function test_paginate_with_filters_by_search_field(): void
    {
        $a = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $b = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $this->lot($a->id, 1000);
        $this->lot($b->id, 2000);

        $this->assertSame(1, $this->repo->paginateWithFilters(['search_field' => 'member', 'search_keyword' => 'Alice'], 20)->total());
        $this->assertSame(1, $this->repo->paginateWithFilters(['search_field' => 'member_id', 'search_keyword' => (string) $b->id], 20)->total());
        $this->assertSame(1, $this->repo->paginateWithFilters(['search_field' => 'email', 'search_keyword' => 'alice@'], 20)->total());
    }

    /**
     * 정렬 — sort 슬러그.
     */
    public function test_paginate_with_filters_sort(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 3000);
        $this->lot($user->id, 1000);

        $asc = $this->repo->paginateWithFilters(['sort' => 'amount_asc'], 20);
        $this->assertSame(1000.0, (float) $asc->items()[0]->amount);

        $desc = $this->repo->paginateWithFilters(['sort' => 'amount_desc'], 20);
        $this->assertSame(3000.0, (float) $desc->items()[0]->amount);
    }
}
