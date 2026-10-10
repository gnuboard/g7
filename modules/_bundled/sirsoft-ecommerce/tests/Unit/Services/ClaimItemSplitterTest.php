<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Services\ClaimItemSplitter;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimAdjustmentOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 신청 상품 수량 분할기 테스트 (§4-2)
 *
 * 검증 축:
 * - **금액 합 보존** — 조각마다 따로 반올림하면 합이 원본과 몇 원씩 어긋난 채 남는다.
 *   세 유형의 안분 대상 컬럼을 각각 나눠서 원본 합과 비교한다.
 * - 원본 = 전이분 / 신규 = 잔여 — 부분 성공 계약의 `succeeded: [id]` 가 운영자가 체크한
 *   행 ID 여야 하므로 방향이 주문상세 분할과 반대다.
 * - 신청 시점 스냅샷 승계와 완료 시각 비움
 * - 진입 가드 4종 (범위 밖 · 전량 no-op · 종결 행 · 교환 후속 옵션)
 */
class ClaimItemSplitterTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimAdjustmentOrders;

    protected ClaimItemSplitter $splitter;

    /**
     * 테스트용 통화 설정을 준비하고 분할기 인스턴스를 받아 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->splitter = app(ClaimItemSplitter::class);
    }

    /**
     * 테스트용 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 분할 대상 교환 신청 상품을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문 옵션
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return OrderExchangeOption 신청 상품 행
     */
    protected function makeExchangeItem(Order $order, OrderOption $option, array $attributes = []): OrderExchangeOption
    {
        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'exchange_status' => ExchangeStatusEnum::REQUESTED,
        ]);

        return OrderExchangeOption::factory()->create(array_merge([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => ExchangeStatusEnum::REQUESTED,
            'exchange_quantity' => 3,
            'original_quantity' => 3,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => 29000,
            'price_difference' => 10000,
        ], $attributes));
    }

    /**
     * 분할 대상 반품 신청 상품을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문 옵션
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return OrderReturnOption 신청 상품 행
     */
    protected function makeReturnItem(Order $order, OrderOption $option, array $attributes = []): OrderReturnOption
    {
        $return = OrderReturn::factory()->create([
            'order_id' => $order->id,
            'return_status' => ReturnStatusEnum::REQUESTED,
        ]);

        return OrderReturnOption::factory()->create(array_merge([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => ReturnStatusEnum::REQUESTED,
            'return_quantity' => 3,
            'original_quantity' => 3,
            'original_option_status' => OrderStatusEnum::DELIVERED,
            'unit_price' => 29000,
            'subtotal_amount' => 10000,
        ], $attributes));
    }

    /**
     * 분할 대상 환불 신청 상품을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  OrderOption  $option  주문 옵션
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return OrderRefundOption 신청 상품 행
     */
    protected function makeRefundItem(Order $order, OrderOption $option, array $attributes = []): OrderRefundOption
    {
        $refund = $this->makeDirectRefund($order, $option, 3);

        $item = $refund->refundOptions()->first();
        $item->update(array_merge([
            'quantity' => 3,
            'subtotal_amount' => 10000,
            'discount_amount' => 1000,
            'shipping_amount' => 500,
            'refund_amount' => 9500,
        ], $attributes));

        return $item->refresh();
    }

    // ================================================================
    // 금액 합 보존
    // ================================================================

    /**
     * 교환 행을 2:1 로 나눠도 차액 합이 원본과 같게 보존되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects split_preserves_the_exchange_price_difference_sum
     */
    public function test_split_preserves_the_exchange_price_difference_sum(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeExchangeItem($order, $order->options->first());

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 2);

        // 3 을 2:1 로 나누면 안분이 딱 떨어지지 않는다 — 조각마다 따로 반올림하면 합이 어긋난다.
        $this->assertSame(2, (int) $target->exchange_quantity);
        $this->assertSame(1, (int) $remainder->exchange_quantity);
        $this->assertEqualsWithDelta(6666.67, (float) $target->price_difference, 0.001);
        $this->assertEqualsWithDelta(3333.33, (float) $remainder->price_difference, 0.001);
        $this->assertEqualsWithDelta(
            10000.0,
            (float) $target->price_difference + (float) $remainder->price_difference,
            0.001
        );
    }

    /**
     * 잔여 행이 **신청 시점 스냅샷을 그대로 물려받는다.**
     *
     * 금액만 나누고 나머지를 새로 쓰면 잔여 행이 "신청 전 주문옵션이 무엇이었는지" 를
     * 잃는다 — 나중에 그 행을 반려할 때 되돌릴 상태의 근거가 사라진다. 특히
     * `is_new_stock_deducted` 는 교환 후속 재고를 이미 뺐는지를 말하는 플래그라, 잔여가
     * 이 값을 잃으면 재고가 두 번 빠지거나 아예 안 빠진다 — 어느 쪽도 오류를 내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects remainder_inherits_the_request_snapshot_and_clears_completion
     */
    public function test_remainder_inherits_the_request_snapshot_columns(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeExchangeItem($order, $order->options->first(), [
            'is_new_stock_deducted' => true,
            'completed_at' => now(),
        ]);

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 2);

        // 캐스트된 Enum 이 섞여 있으므로 비교 전에 backing value 로 좁힌다.
        $normalize = static fn ($value): string => $value instanceof \BackedEnum
            ? (string) $value->value
            : (string) $value;

        foreach (['original_quantity', 'original_option_status', 'unit_price', 'is_new_stock_deducted'] as $column) {
            $this->assertSame(
                $normalize($target->{$column}),
                $normalize($remainder->{$column}),
                "잔여 행이 `{$column}` 을 물려받지 못했습니다",
            );
        }

        // 완료 시각만은 물려받지 않는다 — 잔여는 아직 아무것도 끝나지 않은 행이다.
        $this->assertNull($remainder->completed_at);
    }

    /**
     * 교환 행을 나눠도 보조 통화별 차액 합이 보존되고 배열로 돌아오는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects split_preserves_the_multi_currency_difference_sum
     */
    public function test_split_preserves_the_multi_currency_difference_sum(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeExchangeItem($order, $order->options->first(), [
            'mc_price_difference' => ['USD' => 10.0, 'JPY' => 1000.0],
        ]);

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 2);

        // 보조 통화는 JSON 캐스트다 — 원시 문자열로 다시 쓰면 두 번 인코딩되어 화면의 보조
        // 통화 줄이 통째로 빈다. 배열로 돌아오는 것 자체가 계약이다.
        $this->assertIsArray($target->mc_price_difference);
        $this->assertIsArray($remainder->mc_price_difference);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $target->mc_price_difference['USD'] + (float) $remainder->mc_price_difference['USD'],
            0.001
        );
        $this->assertEqualsWithDelta(
            1000.0,
            (float) $target->mc_price_difference['JPY'] + (float) $remainder->mc_price_difference['JPY'],
            0.001
        );
    }

    /**
     * 반품 행을 나눠도 소계 합이 원본과 같게 보존되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects split_preserves_the_return_subtotal_sum
     */
    public function test_split_preserves_the_return_subtotal_sum(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeReturnItem($order, $order->options->first());

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 1);

        $this->assertSame(1, (int) $target->return_quantity);
        $this->assertSame(2, (int) $remainder->return_quantity);
        $this->assertEqualsWithDelta(
            10000.0,
            (float) $target->subtotal_amount + (float) $remainder->subtotal_amount,
            0.001
        );
    }

    /**
     * 환불 행을 나눠도 네 금액 컬럼의 합이 모두 보존되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects split_preserves_every_refund_money_column_sum
     */
    public function test_split_preserves_every_refund_money_column_sum(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeRefundItem($order, $order->options->first());

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 2);

        // 네 컬럼 중 하나라도 빠지면 그 컬럼만 어긋난 채 합계 화면은 정상으로 보인다.
        foreach (['subtotal_amount' => 10000.0, 'discount_amount' => 1000.0, 'shipping_amount' => 500.0, 'refund_amount' => 9500.0] as $column => $expected) {
            $this->assertEqualsWithDelta(
                $expected,
                (float) $target->{$column} + (float) $remainder->{$column},
                0.001,
                "환불 행 {$column} 합이 보존되지 않았다"
            );
        }
    }

    // ================================================================
    // 방향과 승계
    // ================================================================

    /**
     * 전이분이 원본 행 ID 에 남고 잔여가 새 행으로 생기는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects split_keeps_the_transitioned_share_on_the_original_row
     */
    public function test_split_keeps_the_transitioned_share_on_the_original_row(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeReturnItem($order, $order->options->first());
        $originalId = $item->id;

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 2);

        // 선택한 id 가 잔여로 남으면 "승인했는데 succeeded 에 없는 id" 가 된다.
        $this->assertSame($originalId, $target->id);
        $this->assertNotSame($originalId, $remainder->id);
    }

    /**
     * 반품 잔여 행이 신청 시점 스냅샷을 물려받고 완료 시각은 비우는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects remainder_inherits_the_request_snapshot_and_clears_completion
     */
    public function test_remainder_inherits_the_request_snapshot_and_clears_completion(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeReturnItem($order, $order->options->first(), [
            'completed_at' => now(),
        ]);

        ['remainder' => $remainder] = $this->splitter->split($item, 2);

        $this->assertSame(3, (int) $remainder->original_quantity);
        $this->assertSame(OrderStatusEnum::DELIVERED, $remainder->original_option_status);
        $this->assertEqualsWithDelta(29000.0, (float) $remainder->unit_price, 0.001);
        $this->assertSame($item->order_option_id, $remainder->order_option_id);
        $this->assertSame($item->order_return_id, $remainder->order_return_id);
        $this->assertNull($remainder->completed_at);
    }

    // ================================================================
    // 진입 가드
    // ================================================================

    /**
     * 전량 분할은 행을 늘리지 않는 no-op 인지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects full_quantity_split_is_a_no_op
     */
    public function test_full_quantity_split_is_a_no_op(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeReturnItem($order, $order->options->first());

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 3);

        // 수량을 싣지 않는 호출부가 이 분기로 들어온다 — 여기서 행이 늘면 전량 전이가
        // 매번 표를 한 줄씩 늘린다.
        $this->assertNull($remainder);
        $this->assertSame($item->id, $target->id);
        $this->assertSame(1, OrderReturnOption::where('order_return_id', $item->order_return_id)->count());
    }

    /**
     * 행 수량 범위 밖(0, 초과) 분할이 거부되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects quantity_outside_the_row_is_rejected
     */
    public function test_quantity_outside_the_row_is_rejected(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeReturnItem($order, $order->options->first());

        $this->assertClaimException(
            'claim_quantity_invalid',
            fn () => $this->splitter->split($item, 4)
        );

        $this->assertClaimException(
            'claim_quantity_invalid',
            fn () => $this->splitter->split($item, 0)
        );
    }

    /**
     * 발송된 교환 행도 쪼갤 수 있고, 후속 주문옵션이 같은 수량으로 함께 쪼개진다. (R10 · D11)
     *
     * 후속 주문옵션은 `quantity = exchange_quantity` 1:1 산출물이다 — 예전에는 그래서 발송된 행을
     * 쪼개지 못했고, 선교환에서 3개 중 1개만 회수 송장이 붙은 경우를 적을 수 없었다. 이제 교환 행을
     * 쪼개면 후속 주문옵션도 같은 수량으로 쪼개 잔여 행이 자기 몫을 가리키고, 금액 합은 보존된다.
     *
     * @return void 반환값 없음
     *
     * @effects exchange_row_with_a_redelivery_option_splits_its_successor
     */
    public function test_exchange_row_with_a_redelivery_option_splits_its_successor(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $successor = OrderOption::factory()->create([
            'order_id' => $order->id,
            'quantity' => 3,
            'subtotal_tax_amount' => 900,
            'subtotal_discount_amount' => 300,
        ]);

        $item = $this->makeExchangeItem($order, $order->options->first(), [
            'new_order_option_id' => $successor->id,
            'option_status' => ExchangeStatusEnum::REDELIVERING,
        ]);

        $this->assertTrue(ClaimItemSplitter::isSplittable($item));

        ['target' => $target, 'remainder' => $remainder] = $this->splitter->split($item, 1);

        $this->assertSame(1, (int) $target->exchange_quantity);
        $this->assertSame(2, (int) $remainder->exchange_quantity);
        $this->assertSame((int) $successor->id, (int) $target->new_order_option_id);
        $this->assertNotNull($remainder->new_order_option_id);
        $this->assertNotSame((int) $successor->id, (int) $remainder->new_order_option_id);

        $kept = $successor->fresh();
        $split = OrderOption::find($remainder->new_order_option_id);

        $this->assertSame(1, (int) $kept->quantity);
        $this->assertSame(2, (int) $split->quantity);
        $this->assertEqualsWithDelta(900, (float) $kept->subtotal_tax_amount + (float) $split->subtotal_tax_amount, 0.01);
        $this->assertEqualsWithDelta(300, (float) $kept->subtotal_discount_amount + (float) $split->subtotal_discount_amount, 0.01);
    }

    /**
     * 종결된 환불 행은 분할할 수 없는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects finished_row_is_not_splittable
     */
    public function test_finished_row_is_not_splittable(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $item = $this->makeRefundItem($order, $order->options->first(), [
            'option_status' => RefundStatusEnum::REJECTED,
        ]);

        $this->assertFalse(ClaimItemSplitter::isSplittable($item));
        $this->assertClaimException(
            'claim_item_not_splittable',
            fn () => $this->splitter->split($item, 2)
        );
    }

    /**
     * 진행 중인 반품 행은 분할할 수 있는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects living_row_is_splittable
     */
    public function test_living_row_is_splittable(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);

        $this->assertTrue(ClaimItemSplitter::isSplittable($this->makeReturnItem($order, $order->options->first())));
    }
}
