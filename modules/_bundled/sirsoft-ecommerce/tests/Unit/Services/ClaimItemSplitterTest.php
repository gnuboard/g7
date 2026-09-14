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

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->splitter = app(ClaimItemSplitter::class);
    }

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
     * @effects exchange_row_with_a_redelivery_option_is_not_splittable
     */
    public function test_exchange_row_with_a_redelivery_option_is_not_splittable(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);
        $newOption = OrderOption::factory()->create(['order_id' => $order->id]);

        $item = $this->makeExchangeItem($order, $order->options->first(), [
            'new_order_option_id' => $newOption->id,
        ]);

        // 후속 주문옵션은 `quantity = exchange_quantity` 1:1 산출물이라, 두 조각이 같은 것을
        // 가리키면 재발송 수량이 오류 없이 어긋난다.
        $this->assertFalse(ClaimItemSplitter::isSplittable($item));
        $this->assertClaimException(
            'claim_item_not_splittable',
            fn () => $this->splitter->split($item, 2)
        );
    }

    /**
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
     * @effects living_row_is_splittable
     */
    public function test_living_row_is_splittable(): void
    {
        $order = $this->createSingleItemClaimOrder(29000, 3);

        $this->assertTrue(ClaimItemSplitter::isSplittable($this->makeReturnItem($order, $order->options->first())));
    }
}
