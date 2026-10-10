<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 전량 반품을 완료하면 주문 상태가 「반품완료」가 된다
 *
 * 교환·반품은 진행 중에는 주문 상태를 바꾸지 않고 신청서 상태로 보인다. 그런데 완료 뒤에도 주문을 그대로 두어,
 * 상품이 전부 반품완료(일부는 취소)인 주문이 「배송완료」로 남았다 — 목록에서 끝난 주문으로 보이지 않고, 개인정보
 * 보존기간 파기도 구매확정·취소만 종결로 봐서 영영 대상이 되지 못했다. 살아 있는 상품이 하나도 남지 않을 때만
 * 반품완료로 바꾼다 — 부분 반품·진행 중 클레임·전량 교환은 종전대로다.
 *
 * 시나리오 매니페스트: `tests/scenarios/order-fully-returned-status.yaml`
 */
class FullyReturnedOrderStatusTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private OrderReturnService $returns;

    /**
     * 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->returns = app(OrderReturnService::class);
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 두 상품(각 10,000원 × 1) 배송완료 주문을 만듭니다.
     *
     * @return Order 배송완료 주문
     */
    private function twoItemDeliveredOrder(): Order
    {
        $policy = $this->createClaimShippingPolicy();
        [$pA, $oA] = $this->createClaimProduct(10000, $policy);
        [$pB, $oB] = $this->createClaimProduct(10000, $policy);
        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 1),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 1),
        ]), ['currency_snapshot' => $this->krwCurrencySnapshot()]);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::DELIVERED);
    }

    /**
     * 지정 행을 반품 접수 → 회수도착 → 완료까지 진행합니다.
     *
     * @param  Order  $order  주문
     * @param  array<int>  $optionIds  반품할 주문 행 ID
     * @return OrderReturn 완료된 반품
     */
    private function completeReturnOf(Order $order, array $optionIds): OrderReturn
    {
        $return = $this->returns->requestReturn($order, [
            'items' => array_map(fn (int $id) => ['order_option_id' => $id, 'quantity' => 1], $optionIds),
            'reason_code' => 'defective_product',
            'requested_by' => $order->user_id,
        ]);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::APPROVED);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $return = $this->returns->transition($return->fresh(), ReturnStatusEnum::COLLECTED);

        return app(AdminClaimService::class)->complete($return->fresh(), [], new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: false));
    }

    /**
     * 모든 상품을 반품 완료하면 주문이 반품완료가 된다.
     *
     * @scenario remaining=none, mix=returned_only
     *
     * @effects fully_returned_order_becomes_returned
     *
     * @return void 반환값 없음
     */
    public function test_full_return_marks_the_order_returned(): void
    {
        $order = $this->twoItemDeliveredOrder();
        $this->completeReturnOf($order, $order->options->pluck('id')->all());

        $this->assertSame(OrderStatusEnum::RETURNED, $order->fresh()->order_status, '전량 반품을 마쳤는데 주문이 배송완료로 남았습니다.');
    }

    /**
     * 일부를 취소하고 나머지를 반품 완료해도 살아 있는 상품이 없으므로 반품완료다.
     *
     * @scenario remaining=none, mix=returned_and_cancelled
     *
     * @effects fully_returned_order_becomes_returned
     *
     * @return void 반환값 없음
     */
    public function test_return_with_cancelled_rest_marks_the_order_returned(): void
    {
        $order = $this->twoItemDeliveredOrder();
        [$first, $second] = $order->options->values()->all();
        $second->forceFill(['option_status' => OrderStatusEnum::CANCELLED])->save();

        $this->completeReturnOf($order->fresh(['options', 'shippings', 'payment']), [$first->id]);

        $this->assertSame(OrderStatusEnum::RETURNED, $order->fresh()->order_status);
    }

    /**
     * 살아 있는 상품이 남는 부분 반품은 주문 상태를 바꾸지 않는다 (회귀 가드).
     *
     * @scenario remaining=some, mix=returned_only
     *
     * @effects partial_or_in_progress_return_keeps_order_status
     *
     * @return void 반환값 없음
     */
    public function test_partial_return_keeps_the_order_status(): void
    {
        $order = $this->twoItemDeliveredOrder();
        $this->completeReturnOf($order, [$order->options->first()->id]);

        $this->assertSame(OrderStatusEnum::DELIVERED, $order->fresh()->order_status);
    }

    /**
     * 다른 상품이 아직 교환 진행 중이면 끝난 주문이 아니다 — 주문 상태를 바꾸지 않는다.
     *
     * @scenario remaining=in_progress, mix=returned_only
     *
     * @effects partial_or_in_progress_return_keeps_order_status
     *
     * @return void 반환값 없음
     */
    public function test_return_with_claim_in_progress_keeps_the_order_status(): void
    {
        $order = $this->twoItemDeliveredOrder();
        [$first, $second] = $order->options->values()->all();
        $second->forceFill(['option_status' => OrderStatusEnum::EXCHANGE_IN_PROGRESS])->save();

        $this->completeReturnOf($order->fresh(['options', 'shippings', 'payment']), [$first->id]);

        $this->assertSame(OrderStatusEnum::DELIVERED, $order->fresh()->order_status);
    }

    /**
     * 반품완료 주문을 상태 동기화가 다시 훑어도 「취소」로 뒤집지 않는다 — 활성 상품이 없다는 이유만으로 취소로 보던 규칙.
     *
     * @scenario remaining=none, mix=returned_only
     *
     * @effects fully_returned_order_becomes_returned
     *
     * @return void 반환값 없음
     */
    public function test_status_sync_does_not_flip_a_returned_order_to_cancelled(): void
    {
        $order = $this->twoItemDeliveredOrder();
        $this->completeReturnOf($order, $order->options->pluck('id')->all());

        app(OrderOptionService::class)->syncParentOrderStatus($order->id);

        $this->assertSame(OrderStatusEnum::RETURNED, $order->fresh()->order_status, '활성 상품이 없다고 반품완료 주문을 취소로 바꿨습니다.');
    }

    /**
     * 보존기간이 지난 반품완료 주문은 개인정보 파기 대상이다 — 마지막 반품 완료 시각 기준.
     *
     * @scenario remaining=none, mix=returned_only
     *
     * @effects fully_returned_order_is_a_retention_target
     *
     * @return void 반환값 없음
     */
    public function test_returned_order_past_retention_is_a_privacy_target(): void
    {
        $order = $this->twoItemDeliveredOrder();
        $return = $this->completeReturnOf($order, $order->options->pluck('id')->all());
        $handler = app(OrderRetentionHandler::class);

        $this->assertFalse($handler->targetQuery()->whereKey($order->id)->exists(), '방금 끝난 주문은 대상이 아니다');

        $past = now()->subYears(10);
        $return->forceFill(['completed_at' => $past])->save();
        $order->forceFill(['updated_at' => $past])->saveQuietly();

        $this->assertTrue($handler->targetQuery()->whereKey($order->id)->exists(), '보존기간이 지난 반품완료 주문이 파기 대상에서 빠졌습니다.');
    }

    /**
     * 관리자 주문 목록의 주문 상태 필터 칩이 주문이 가질 수 있는 상태를 모두 담는다 — 반품완료 주문도 골라 볼 수 있다.
     *
     * 주문 헤더는 클레임 코스 상태 중 반품완료만 갖는다(진행 중 교환·반품은 주문 상태를 바꾸지 않고, 교환완료는 상품 행에만
     * 남는다). 칩에서 빠진 상태의 주문은 어떤 필터 조합으로도 골라 볼 수 없다.
     *
     * @scenario remaining=none, mix=returned_only
     *
     * @effects returned_order_reachable_by_status_filter
     *
     * @return void 반환값 없음
     */
    public function test_order_status_filter_chips_cover_every_status_an_order_can_take(): void
    {
        $headerStatuses = array_values(array_diff(
            OrderStatusEnum::values(),
            array_map(
                fn (OrderStatusEnum $s) => $s->value,
                array_filter(OrderStatusEnum::claimCourseStatuses(), fn (OrderStatusEnum $s) => $s !== OrderStatusEnum::RETURNED),
            ),
        ));
        $this->assertContains(OrderStatusEnum::RETURNED->value, $headerStatuses, '모집단 파생이 반품완료를 빠뜨렸습니다.');

        $layout = json_decode(file_get_contents(__DIR__.'/../../../resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json'), true, 512, JSON_THROW_ON_ERROR);
        $chips = [];
        $walk = function ($node) use (&$walk, &$chips): void {
            if (! is_array($node)) {
                return;
            }
            if (($node['name'] ?? null) === 'ChipCheckbox' && str_contains((string) json_encode($node), 'filter.orderStatus')) {
                $chips[] = (string) ($node['props']['value'] ?? '');
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($layout);

        $this->assertNotEmpty($chips, '주문 상태 필터 칩을 찾지 못했습니다.');
        $this->assertSame([], array_values(array_diff($headerStatuses, $chips)), '주문이 가질 수 있는데 필터 칩에 없는 상태가 있습니다.');
        $this->assertSame([], array_values(array_diff($chips, OrderStatusEnum::values())), '서버가 모르는 상태 칩이 있습니다.');
    }

    /**
     * 교환을 마친 뒤 받은 상품을 전부 반품하면 반품완료다 — 교환완료로 닫힌 원래 상품은 살아 있는 상품이 아니다.
     *
     * @scenario remaining=none, mix=exchanged_then_returned
     *
     * @effects fully_returned_order_becomes_returned
     *
     * @return void 반환값 없음
     */
    public function test_return_of_every_exchanged_item_marks_the_order_returned(): void
    {
        $order = $this->twoItemDeliveredOrder();
        [$source, $replacement] = $order->options->values()->all();
        $source->forceFill(['option_status' => OrderStatusEnum::EXCHANGED])->save();
        $replacement->forceFill(['option_status' => OrderStatusEnum::RETURNED, 'source_option_id' => $source->id])->save();

        app(OrderOptionService::class)->syncFullyReturnedOrderStatus($order->id);

        $this->assertSame(OrderStatusEnum::RETURNED, $order->fresh()->order_status, '교환으로 받은 상품을 전부 반품했는데 주문이 반품완료가 되지 않았습니다.');
    }

    /**
     * 교환으로 받은 상품까지 취소돼 살아 있는 상품이 없으면 상태 동기화는 종전대로 취소로 바꾼다 (회귀 가드).
     *
     * @scenario remaining=none, mix=exchanged_then_cancelled
     *
     * @effects fully_returned_order_becomes_returned
     *
     * @return void 반환값 없음
     */
    public function test_status_sync_cancels_an_order_whose_exchanged_item_was_cancelled(): void
    {
        $order = $this->twoItemDeliveredOrder();
        [$source, $replacement] = $order->options->values()->all();
        $source->forceFill(['option_status' => OrderStatusEnum::EXCHANGED])->save();
        $replacement->forceFill(['option_status' => OrderStatusEnum::CANCELLED, 'source_option_id' => $source->id])->save();

        app(OrderOptionService::class)->syncParentOrderStatus($order->id);

        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status, '살아 있는 상품이 없는 주문을 취소로 바꾸지 않았습니다.');
    }
}
