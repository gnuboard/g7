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

    /**
     * 컨테이너에서 마일리지 거래 저장소를 꺼내 테스트 대상으로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(MileageTransactionRepositoryInterface::class);
    }

    /**
     * 구매 적립(purchase_earn) lot 한 건을 만듭니다 — 잔여액이 적립액과 같은 새 lot 입니다.
     *
     * @param  int  $userId  회원 ID
     * @param  float  $amount  적립액(=잔여액)
     * @param  string|null  $currency  통화 코드
     * @param  mixed  $expiresAt  만료 일시 (null 이면 무기한)
     * @return MileageTransaction 생성된 적립 lot
     */
    private function lot(int $userId, float $amount, ?string $currency = 'KRW', $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId, 'currency' => $currency, 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => $amount, 'remaining_amount' => $amount, 'balance_after' => $amount, 'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 잔액은 만료되지 않은 lot 의 잔여액 합계이며, 만료 lot 은 빠집니다.
     *
     * @scenario expiry_state=expired
     *
     * @effects balance_ssot_is_sum_remaining_where_positive_and_not_expired
     *
     * @return void 반환값 없음
     */
    public function test_get_balance_sums_active_lots(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 1000);
        $this->lot($user->id, 500, 'KRW', now()->subDay()); // 만료 제외

        $this->assertSame(1000.0, $this->repo->getBalance($user->id));
        $this->assertSame(1000.0, $this->repo->getBalanceByCurrency($user->id, 'KRW'));
    }

    /**
     * 차감용 활성 lot 은 만료일 오름차순(무기한은 마지막), 같으면 id 순으로 나옵니다.
     *
     * FIFO 차감이 먼저 소멸할 lot 부터 쓰도록 순서를 저장소가 보장한다.
     *
     * @scenario expiry_state=active
     *
     * @effects fifo_deducts_oldest_expiring_lot_first_under_for_update_lock
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문옵션의 적립 원장 존재 여부를 판정합니다 — 적립 멱등성의 근거입니다.
     *
     * @scenario earn_trigger=confirmed
     *
     * @effects earn_idempotent_skips_when_earn_ledger_exists_for_option
     *
     * @return void 반환값 없음
     */
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
     * uuid 를 정수 user_id 로 해석하고, 없는 uuid 는 null 을 돌려줍니다.
     *
     * 관리자 수동 액션의 회원 식별 경로다 — 코어 UserResource 가 id 를 노출하지 않는다.
     *
     * @scenario actor_permission=read_and_manage
     *
     * @effects member_uuid_resolves_to_user_id_or_null
     *
     * @return void 반환값 없음
     */
    public function test_resolve_user_id_by_uuid(): void
    {
        $user = User::factory()->create();

        $this->assertSame($user->id, $this->repo->resolveUserIdByUuid($user->uuid));
        $this->assertNull($this->repo->resolveUserIdByUuid('00000000-0000-0000-0000-000000000000'));
    }

    /**
     * 취소 건(order_cancel_id)의 복원 원장 존재 여부를 판정합니다 — 복원 멱등성의 근거입니다.
     *
     * @scenario cancel_kind=full_cancel
     *
     * @effects restore_for_cancel_idempotent_by_order_cancel_id
     *
     * @return void 반환값 없음
     */
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

    /**
     * lot 잔여액을 지정 금액만큼 차감합니다.
     *
     * @scenario expiry_state=active
     *
     * @effects lot_decrement_applies_to_the_committed_value
     *
     * @return void 반환값 없음
     */
    public function test_decrement_remaining(): void
    {
        $user = User::factory()->create();
        $lot = $this->lot($user->id, 1000);

        $this->repo->decrementRemaining($lot, 300);

        $this->assertSame(700.0, (float) $lot->fresh()->remaining_amount);
    }

    /**
     * 적립 대상 옵션은 트리거 도달 + 지연 경과 + 적립 원장 부재 + 적립액 > 0 인 것만입니다.
     *
     * 적립액 0 옵션과 이미 적립 원장이 있는 옵션은 빠진다 — 적립 스케줄러의 재실행 멱등성 근거.
     *
     * @scenario earn_trigger=confirmed, earn_delay_days=positive
     *
     * @effects earn_mileage_command_processes_trigger_reached_options_past_delay_idempotently
     *
     * @return void 반환값 없음
     */
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

    /**
     * 적립 lot 기준으로 그 lot 을 소비한 거래(사용 등)를 연결 거래로 돌려줍니다.
     *
     * @scenario expiry_state=active
     *
     * @effects admin_mileage_row_expand_shows_linked_transactions
     *
     * @return void 반환값 없음
     */
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
     * 거래유형 필터는 UI 4분류 슬러그(earn/use/expire/adjust)를 받아 8종 enum 으로 역매핑합니다.
     *
     * 관리자 차감(admin_deduct)은 사용도 소멸도 아닌 차감이라 `adjust` 로 분류되고,
     * 잔액이 는 거래는 전부 `earn`(적립)으로 분류된다.
     *
     * @scenario type_filter=adjust
     *
     * @effects type_slug_reverse_maps_to_enum
     *
     * @return void 반환값 없음
     */
    public function test_paginate_with_filters_by_type_category(): void
    {
        $user = User::factory()->create();
        $this->lot($user->id, 1000); // purchase_earn → 'earn'
        MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_DEDUCT->value,
            'amount' => -200, 'remaining_amount' => 0, 'balance_after' => 800, // admin_deduct → 'adjust'
        ]);

        // 'adjust' 슬러그는 사용도 소멸도 아닌 차감(관리자 차감·기적립 회수)만 매칭 (purchase_earn 제외)
        $this->assertSame(1, $this->repo->paginateWithFilters(['type' => 'adjust'], 20)->total());

        // 'earn' 슬러그는 잔액이 는 거래만 매칭 (admin_deduct 는 adjust)
        $this->assertSame(1, $this->repo->paginateWithFilters(['type' => 'earn'], 20)->total());
    }

    /**
     * 검색은 search_field 별로 분기합니다 (회원명·회원 ID 직접 / 이메일 관계).
     *
     * @scenario search_field=member
     *
     * @effects search_field_branches_member_memberid_email_order
     *
     * @return void 반환값 없음
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
     * 정렬 슬러그(amount_asc / amount_desc)가 금액 정렬로 반영됩니다.
     *
     * @scenario list_state=single_page
     *
     * @effects sort_slug_applied
     *
     * @return void 반환값 없음
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

    /**
     * lot 잔여 차감은 낡은 스냅샷의 값을 덮어쓰지 않아야 합니다.
     *
     * 값을 PHP 에서 빼고 모델 전체를 저장하면, 그 사이 다른 요청이 반영한 증액이
     * 통째로 사라진다 (KVE-2026-1886 동종 lost update).
     *
     * @scenario expiry_state=active
     *
     * @effects lot_decrement_applies_to_the_committed_value
     *
     * @return void 반환값 없음
     */
    public function test_decrement_remaining_does_not_overwrite_concurrent_change(): void
    {
        $user = User::factory()->create();
        $lot = $this->lot($user->id, 1000);

        // 리스너가 손에 쥔 낡은 스냅샷 (remaining=1000)
        $stale = MileageTransaction::find($lot->id);

        // 그 사이 다른 요청이 lot 을 증액했다 (remaining 1000 → 1500)
        MileageTransaction::query()->where('id', $lot->id)->update(['remaining_amount' => 1500]);

        $this->repo->decrementRemaining($stale, 100);

        $this->assertSame(
            1400.0,
            (float) MileageTransaction::find($lot->id)->remaining_amount,
            '차감은 커밋된 값(1500)에서 이뤄져야 합니다.'
        );
    }

    /**
     * lot 증액도 낡은 스냅샷의 값을 덮어쓰지 않아야 합니다.
     *
     * @scenario expiry_state=active
     *
     * @effects lot_increment_applies_to_the_committed_value_and_refreshes_the_model
     *
     * @return void 반환값 없음
     */
    public function test_increment_earn_lot_amount_does_not_overwrite_concurrent_change(): void
    {
        $user = User::factory()->create();
        $lot = $this->lot($user->id, 1000);

        $stale = MileageTransaction::find($lot->id);

        MileageTransaction::query()->where('id', $lot->id)->update([
            'amount' => 1500,
            'remaining_amount' => 1500,
        ]);

        $this->repo->incrementEarnLotAmount($stale, 200);

        $fresh = MileageTransaction::find($lot->id);
        $this->assertSame(1700.0, (float) $fresh->amount, '증액은 커밋된 값(1500)에 더해져야 합니다.');
        $this->assertSame(1700.0, (float) $fresh->remaining_amount);
    }

    /**
     * 증액 후 돌려받는 모델은 반영된 값을 들고 있어야 합니다.
     *
     * 호출부가 이 모델을 그대로 반환·기록하므로, 낡은 값이 남으면 화면·로그가 어긋난다.
     *
     * @scenario expiry_state=active
     *
     * @effects lot_increment_applies_to_the_committed_value_and_refreshes_the_model
     *
     * @return void 반환값 없음
     */
    public function test_increment_earn_lot_amount_refreshes_the_model(): void
    {
        $user = User::factory()->create();
        $lot = $this->lot($user->id, 1000);

        $this->repo->incrementEarnLotAmount($lot, 200);

        $this->assertSame(1200.0, (float) $lot->amount);
        $this->assertSame(1200.0, (float) $lot->remaining_amount);
    }

    /**
     * 적립 lot 조회의 잠금 변형이 같은 행을 돌려줘야 합니다.
     *
     * @scenario earn_trigger=confirmed
     *
     * @effects earn_lot_lookup_for_update_returns_the_same_row
     *
     * @return void 반환값 없음
     */
    public function test_find_earn_lot_for_option_for_update_returns_same_row(): void
    {
        $user = User::factory()->create();
        $orderOptionId = 987654;

        $lot = MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 500,
            'remaining_amount' => 500,
            'balance_after' => 500,
            'order_option_id' => $orderOptionId,
        ]);

        $found = $this->repo->findEarnLotForOptionForUpdate($orderOptionId);

        $this->assertNotNull($found);
        $this->assertSame($lot->id, $found->id);
    }
}
