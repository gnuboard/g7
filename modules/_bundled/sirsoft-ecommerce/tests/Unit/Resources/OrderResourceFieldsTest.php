<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderResource / OrderOptionResource 필드 테스트
 *
 * 주문상세 합계행 수정 및 다통화 표시 추가 관련 필드 검증
 */
class OrderResourceFieldsTest extends ModuleTestCase
{
    /**
     * OrderOptionResource에 final_amount, final_amount_formatted 필드가 포함되는지 확인
     *
     * @effects option_final_amount_deducts_discount_points_and_deposit
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_includes_final_amount_fields(): void
    {
        // Given: 주문 옵션 생성 (subtotal_price=30000, discount=1000, points=0, deposit=0)
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 10000,
            'quantity' => 3,
            'subtotal_price' => 30000,
            'subtotal_discount_amount' => 1000,
            'subtotal_points_used_amount' => 0,
            'subtotal_deposit_used_amount' => 0,
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: final_amount = 30000 - 1000 - 0 - 0 = 29000
        $this->assertEquals(29000, $resource['final_amount']);
        $this->assertEquals('29,000원', $resource['final_amount_formatted']);
    }

    /**
     * OrderOptionResource에 list_price 필드가 option_snapshot에서 추출되는지 확인
     *
     * @effects option_list_price_comes_from_the_option_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_includes_list_price_from_snapshot(): void
    {
        // Given: option_snapshot에 list_price=15000, unit_price=10000
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 10000,
            'option_snapshot' => [
                'list_price' => 15000,
                'selling_price' => 10000,
            ],
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: list_price가 스냅샷에서 추출
        $this->assertEquals(15000, $resource['list_price']);
        $this->assertEquals('15,000원', $resource['list_price_formatted']);
    }

    /**
     * 옵션 스냅샷에 정가가 없으면 상품 정가에 옵션 조정액을 더한 값이 정가다 (실측 2026-10-03).
     *
     * 판매가는 조정액을 더해 저장되는데 정가만 상품 정가 그대로 내려, 블루(+3,000) 옵션이
     * 「정가 ¥4,000 / 판매가 ¥5,000」으로 정가가 판매가보다 낮게 찍혔다. 클레임 비교 카드는
     * 같은 주문의 정가를 ¥7,000 으로 계산해 두 화면이 갈렸다.
     *
     * @effects option_list_price_adds_the_option_adjustment_without_an_option_list_price
     *
     * @return void 반환값 없음
     */
    public function test_order_option_list_price_adds_option_adjustment_when_option_snapshot_has_none(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 5000,
            'quantity' => 2,
            'subtotal_price' => 10000,
            'product_snapshot' => ['list_price' => '4000.00', 'selling_price' => '2000.00'],
            'option_snapshot' => ['list_price' => null, 'selling_price' => null, 'price_adjustment' => '3000.00'],
        ]);

        $resource = (new OrderOptionResource($option))->resolve();
        $this->assertEquals(7000, $resource['list_price']);

        $order->load('options');
        $orderResource = (new OrderResource($order))->resolve();
        $this->assertEquals(14000, $orderResource['total_list_price']);
    }

    /**
     * OrderOptionResource에서 mc_subtotal_discount_amount 필드가 제거되었는지 확인
     *
     * @effects option_resource_drops_the_removed_mc_discount_field
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_does_not_include_mc_subtotal_discount_amount(): void
    {
        // Given: 주문 옵션 생성
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create();

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: mc_subtotal_discount_amount 필드 미존재 (버그 수정)
        $this->assertArrayNotHasKey('mc_subtotal_discount_amount', $resource);
    }

    /**
     * OrderResource에 total_quantity 필드가 옵션 수량 합계를 반환하는지 확인
     *
     * @effects order_total_quantity_sums_the_option_quantities
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_includes_total_quantity(): void
    {
        // Given: 주문에 옵션 3개 (수량 2, 3, 5)
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 2, 'unit_price' => 10000, 'subtotal_price' => 20000]);
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 3, 'unit_price' => 10000, 'subtotal_price' => 30000]);
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 5, 'unit_price' => 10000, 'subtotal_price' => 50000]);

        // When: 리소스 변환 (options 관계 로드 필요)
        $order->load('options');
        $resource = (new OrderResource($order))->resolve();

        // Then: total_quantity = 2 + 3 + 5 = 10
        $this->assertEquals(10, $resource['total_quantity']);
    }

    /**
     * 합계 줄의 정가·수량은 판매가·결제액과 같은 「남은 상품」 기준이다.
     *
     * 종전에는 정가·수량만 취소 행까지 더해, 부분취소 주문에서 「정가 114,000 · 4개」 옆에 「판매가 57,000」이
     * 서는 기준 혼재가 생겼다. 교환이 끝난 주문은 원 행과 후속 행이 같은 상품을 두 번 세었다. 판정은 재계산
     * 입력과 같다 — 취소 행·교환완료로 닫힌 원 행은 빼고, 원 행이 아직 교환 진행 중인 후속 행도 뺀다.
     *
     * @effects order_total_list_price_and_quantity_count_only_accountable_rows
     *
     * @return void 반환값 없음
     */
    public function test_order_totals_count_only_accountable_rows(): void
    {
        $snapshot = fn (int $list) => ['list_price' => $list, 'selling_price' => $list];

        // 부분취소 — 살아 있는 행 2개 × 28,500, 취소 행 2개 × 28,500
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 2, 'unit_price' => 28500, 'subtotal_price' => 57000, 'option_status' => OrderStatusEnum::DELIVERED, 'option_snapshot' => $snapshot(28500)]);
        OrderOptionFactory::new()->forOrder($order)->create(['quantity' => 2, 'unit_price' => 28500, 'subtotal_price' => 57000, 'option_status' => OrderStatusEnum::CANCELLED, 'option_snapshot' => $snapshot(28500)]);
        $resource = (new OrderResource($order->load('options')))->resolve();
        $this->assertEquals(57000, $resource['total_list_price'], '취소 행 정가를 더했습니다.');
        $this->assertEquals(2, $resource['total_quantity'], '취소 행 수량을 더했습니다.');

        // 교환 완료 — 원 행(EXCHANGED)은 빼고 후속 행(교환으로 들어온 상품)은 센다
        $exchanged = OrderFactory::new()->create();
        $source = OrderOptionFactory::new()->forOrder($exchanged)->create(['quantity' => 1, 'unit_price' => 10000, 'subtotal_price' => 10000, 'option_status' => OrderStatusEnum::EXCHANGED, 'option_snapshot' => $snapshot(10000)]);
        OrderOptionFactory::new()->forOrder($exchanged)->create(['quantity' => 1, 'unit_price' => 12000, 'subtotal_price' => 12000, 'option_status' => OrderStatusEnum::DELIVERED, 'option_snapshot' => $snapshot(12000), 'source_type' => 'exchange', 'source_option_id' => $source->id]);
        $resource = (new OrderResource($exchanged->load('options')))->resolve();
        $this->assertEquals(12000, $resource['total_list_price'], '교환 완료 주문의 원 행과 후속 행을 함께 셌습니다.');
        $this->assertEquals(1, $resource['total_quantity']);

        // 교환 진행 중 — 원 행이 아직 주체이므로 후속 행을 빼고 원 행을 센다
        $inProgress = OrderFactory::new()->create();
        $source = OrderOptionFactory::new()->forOrder($inProgress)->create(['quantity' => 1, 'unit_price' => 10000, 'subtotal_price' => 10000, 'option_status' => OrderStatusEnum::EXCHANGE_IN_PROGRESS, 'option_snapshot' => $snapshot(10000)]);
        OrderOptionFactory::new()->forOrder($inProgress)->create(['quantity' => 1, 'unit_price' => 12000, 'subtotal_price' => 12000, 'option_status' => OrderStatusEnum::PREPARING, 'option_snapshot' => $snapshot(12000), 'source_type' => 'exchange', 'source_option_id' => $source->id]);
        $resource = (new OrderResource($inProgress->load('options')))->resolve();
        $this->assertEquals(10000, $resource['total_list_price'], '교환 진행 중 후속 행을 셌습니다.');
        $this->assertEquals(1, $resource['total_quantity']);
    }

    /**
     * OrderResource에 total_list_price 필드가 스냅샷의 list_price * quantity 합계를 반환하는지 확인
     *
     * @effects order_total_list_price_sums_snapshot_list_price_times_quantity
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_includes_total_list_price(): void
    {
        // Given: 주문에 옵션 2개
        $order = OrderFactory::new()->create();
        // 옵션1: list_price=15000, quantity=2 → 30000
        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal_price' => 20000,
            'option_snapshot' => ['list_price' => 15000, 'selling_price' => 10000],
        ]);
        // 옵션2: list_price=20000, quantity=1 → 20000
        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 18000,
            'subtotal_price' => 18000,
            'option_snapshot' => ['list_price' => 20000, 'selling_price' => 18000],
        ]);

        // When: 리소스 변환
        $order->load('options');
        $resource = (new OrderResource($order))->resolve();

        // Then: total_list_price = 30000 + 20000 = 50000
        $this->assertEquals(50000, $resource['total_list_price']);
        $this->assertEquals('50,000원', $resource['total_list_price_formatted']);
    }

    /**
     * OrderResource의 total_list_price가 list_price 없을 때 unit_price로 폴백하는지 확인
     *
     * @effects order_total_list_price_falls_back_to_unit_price_without_a_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_total_list_price_falls_back_to_unit_price(): void
    {
        // Given: option_snapshot에 list_price가 없는 경우
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal_price' => 20000,
            'option_snapshot' => ['selling_price' => 10000],
            'product_snapshot' => ['selling_price' => 10000],
        ]);

        // When: 리소스 변환
        $order->load('options');
        $resource = (new OrderResource($order))->resolve();

        // Then: unit_price 폴백 → 10000 * 2 = 20000
        $this->assertEquals(20000, $resource['total_list_price']);
    }

    /**
     * OrderResource가 마일리지 사용/적립/환불 마일리지 합계 필드를 노출하는지 확인
     *
     * 회귀 차단: 마일리지 시스템 도입 전 완성된 주문상세/목록 화면이 이 필드를 기대하므로
     * Resource 가 누락하면 화면에 마일리지가 표시되지 않는다.
     *
     * @effects order_exposes_used_earned_and_refunded_mileage_totals
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_exposes_mileage_and_refund_points_fields(): void
    {
        // Given: 마일리지 사용 5000, 적립 2400, 마일리지 환불 1500 인 주문
        $order = OrderFactory::new()->create([
            'total_points_used_amount' => 5000,
            'total_earned_points_amount' => 2400,
            'total_refunded_points_amount' => 1500,
        ]);

        // When: 리소스 변환
        $resource = (new OrderResource($order))->resolve();

        // Then: 사용/적립/환불 마일리지 값 + 포맷이 모두 노출
        $this->assertEquals(5000, $resource['total_points_used_amount']);
        $this->assertEquals('5,000원', $resource['total_points_used_amount_formatted']);
        $this->assertEquals(2400, $resource['total_earned_points_amount']);
        // 적립 마일리지는 화폐가 아니라 포인트라 `P` 로 표기한다 (CHANGELOG 「적립 마일리지 단위 통일」)
        $this->assertEquals('2,400P', $resource['total_earned_points_amount_formatted']);
        $this->assertEquals(1500, $resource['total_refunded_points_amount']);
        $this->assertEquals('1,500원', $resource['total_refunded_points_amount_formatted']);
    }

    /**
     * OrderOptionResource의 final_amount가 할인+마일리지+예치금 모두 차감하는지 확인
     *
     * @effects option_final_amount_deducts_discount_points_and_deposit
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_final_amount_deducts_all(): void
    {
        // Given: 할인+마일리지+예치금 모두 있는 경우
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 50000,
            'quantity' => 1,
            'subtotal_price' => 50000,
            'subtotal_discount_amount' => 5000,
            'subtotal_points_used_amount' => 2000,
            'subtotal_deposit_used_amount' => 1000,
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: final_amount = 50000 - 5000 - 2000 - 1000 = 42000
        $this->assertEquals(42000, $resource['final_amount']);
        $this->assertEquals('42,000원', $resource['final_amount_formatted']);
    }

    /**
     * OrderOptionResource가 재고 적용 여부(is_stock_deducted)를 boolean 으로 노출하는지 확인
     *
     * 주문상세 '구매수량' 컬럼의 재고 차감/미차감 배지가 이 필드를 사용한다.
     *
     * @effects resource_exposes_is_stock_deducted_boolean
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_exposes_is_stock_deducted(): void
    {
        // Given: 재고 차감 완료 옵션 / 미차감 옵션
        $order = OrderFactory::new()->create();
        $deducted = OrderOptionFactory::new()->forOrder($order)->create(['is_stock_deducted' => true]);
        $notDeducted = OrderOptionFactory::new()->forOrder($order)->create(['is_stock_deducted' => false]);

        // When: 리소스 변환
        $deductedResource = (new OrderOptionResource($deducted))->resolve();
        $notDeductedResource = (new OrderOptionResource($notDeducted))->resolve();

        // Then: boolean 타입으로 차감 여부 노출
        $this->assertArrayHasKey('is_stock_deducted', $deductedResource);
        $this->assertTrue($deductedResource['is_stock_deducted']);
        $this->assertFalse($notDeductedResource['is_stock_deducted']);
    }

    /**
     * is_points_earned 가 구매 적립 거래 미발행 시 false 로 노출되는지 확인
     *
     * 적립예정액은 있으나 실제 적립이 아직 발생하지 않은 상태 → '적립예정' 배지.
     *
     * @effects resource_is_points_earned_false_without_earn_tx
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_is_points_earned_false_without_earn_transaction(): void
    {
        // Given: 적립예정액은 있으나 적립 거래는 없는 주문옵션
        $order = OrderFactory::new()->create(['user_id' => $this->createUser()->id]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_earned_points_amount' => 520,
        ]);

        // When: Repository 의 findWithRelations 로 로드 (withExists 집계 경로 검증)
        $loaded = app(OrderRepositoryInterface::class)->findWithRelations($order->id);
        $resource = (new OrderOptionResource($loaded->options->first()))->resolve();

        // Then: 적립 거래 미발행 → false
        $this->assertArrayHasKey('is_points_earned', $resource);
        $this->assertFalse($resource['is_points_earned']);
    }

    /**
     * is_points_earned 가 구매 적립 거래 발행 시 true 로 노출되는지 확인
     *
     * 실제 PURCHASE_EARN 거래가 해당 옵션에 발행된 상태 → '적립완료' 배지.
     *
     * @effects resource_is_points_earned_true_with_purchase_earn
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_is_points_earned_true_with_purchase_earn_transaction(): void
    {
        // Given: 적립예정액이 있는 주문옵션 + 해당 옵션에 PURCHASE_EARN 거래 발행
        $user = $this->createUser();
        $order = OrderFactory::new()->create(['user_id' => $user->id]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_earned_points_amount' => 520,
        ]);
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 520,
            'remaining_amount' => 520,
            'balance_after' => 520,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
        ]);

        // When: Repository 의 findWithRelations 로 로드
        $loaded = app(OrderRepositoryInterface::class)->findWithRelations($order->id);
        $resource = (new OrderOptionResource($loaded->options->first()))->resolve();

        // Then: 구매 적립 발행됨 → true
        $this->assertTrue($resource['is_points_earned']);
    }

    /**
     * is_points_earned 가 적립 외 거래(사용/취소복원)에는 반응하지 않는지 확인
     *
     * existsEarnForOption 의 EARN_TYPES 와 달리, 화면 '적립완료' 판정은
     * 구매 적립(PURCHASE_EARN)만 대상으로 한다. 사용/복원 거래로 오탐되면 안 된다.
     *
     * @effects resource_is_points_earned_ignores_non_purchase_tx
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_is_points_earned_ignores_non_purchase_earn_transactions(): void
    {
        // Given: 적립예정액이 있는 주문옵션 + 해당 옵션에 ORDER_USE(사용) 거래만 존재
        $user = $this->createUser();
        $order = OrderFactory::new()->create(['user_id' => $user->id]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_earned_points_amount' => 520,
        ]);
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ORDER_USE->value,
            'amount' => -1000,
            'remaining_amount' => 0,
            'balance_after' => 0,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
        ]);

        // When: Repository 의 findWithRelations 로 로드
        $loaded = app(OrderRepositoryInterface::class)->findWithRelations($order->id);
        $resource = (new OrderOptionResource($loaded->options->first()))->resolve();

        // Then: 구매 적립이 아니므로 false
        $this->assertFalse($resource['is_points_earned']);
    }

    /**
     * 취소된 옵션은 「지킬 수 없는 적립예정」을 말하지 않는다 (S1-D19)
     *
     * 적립 여부는 단 두 가지(발행됨/아직)로 노출되었지만 도메인 상태는 셋이다.
     * 취소된 옵션은 `MileageTransactionListener` 가 기적립분을 회수하고,
     * 스케줄러의 `getEarnableOptions` 는 `option_status = 적립트리거`(confirmed·delivered)
     * 만 곱으므로 **어느 설정에서도** 다시 적립 대상이 되지 않는다.
     *
     * 실측(#599 통합실측 세션1 · 1-M · 주문 108 `20260915-0604289654`): 전량 취소되어
     * 76,500원 전액 환불된 주문의 옵션 3행이 각각 `228P 적립예정` · `160P 적립예정` ·
     * `440P 적립예정` 을 말하고 있었다 — 그 주문의 `purchase_earn` 거래는 0건이고
     * 앞으로도 0건이다. 오류도 나지 않고 잡히는 신호도 없다 — 운영자가 물어봐야 생기는 차이다.
     *
     * 계약: 취소된 옵션은 적립보다 상위의 상태를 싣는다. 판정은 화면이 아니라
     * Resource 가 소유한다 — 같은 배지를 그리는 자리가 늘어나도 한 자리만 고치면 된다.
     *
     * @scenario stock_deduction=not_deducted, points_earn=earn_cancelled, non_purchase_tx=none
     *
     * @effects resource_marks_cancelled_option_as_earn_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_marks_cancelled_option_as_earn_cancelled(): void
    {
        // Given: 적립예정액은 스냅샷에 남아 있지만 옵션은 취소된 주문
        $order = OrderFactory::new()->create(['user_id' => $this->createUser()->id]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_earned_points_amount' => 520,
            'option_status' => OrderStatusEnum::CANCELLED->value,
        ]);

        // When: Repository 의 findWithRelations 로 로드 (withExists 집계 경로 포함)
        $loaded = app(OrderRepositoryInterface::class)->findWithRelations($order->id);
        $resource = (new OrderOptionResource($loaded->options->first()))->resolve();

        // Then: 지킬 수 없는 예정이 아니라 「적립 없음」으로 읽힌다
        $this->assertArrayHasKey('is_points_earn_cancelled', $resource);
        $this->assertTrue(
            $resource['is_points_earn_cancelled'],
            '취소된 옵션은 다시 적립 대상이 되지 않는다 — 「적립예정」은 지킬 수 없는 약속이다'
        );
        $this->assertFalse($resource['is_points_earned']);
    }

    /**
     * 반품완료·교환완료(원 행)도 「적립 없음」으로 읽힌다 (S3-D20, 2026-10-07 실측)
     *
     * 두 상태도 적립 조건(`option_status = 적립트리거`)에 다시 걸리지 않는다 — 교환으로 받은 상품의 적립은 후속 행이
     * 가진다. 그런데 화면은 「500P 적립예정」을 말했다(실측: 주문 20261007-0346009059 의 교환완료 원 행 A옵션2 ·
     * 반품완료 행 A옵션1).
     *
     * @scenario stock_deduction=not_deducted, points_earn=earn_cancelled, non_purchase_tx=none
     *
     * @effects resource_marks_cancelled_option_as_earn_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_returned_and_exchanged_options_read_as_earn_cancelled(): void
    {
        foreach ([OrderStatusEnum::RETURNED, OrderStatusEnum::EXCHANGED] as $status) {
            $order = OrderFactory::new()->create(['user_id' => $this->createUser()->id]);
            OrderOptionFactory::new()->forOrder($order)->create([
                'subtotal_earned_points_amount' => 500,
                'option_status' => $status->value,
            ]);

            $loaded = app(OrderRepositoryInterface::class)->findWithRelations($order->id);
            $resource = (new OrderOptionResource($loaded->options->first()))->resolve();

            $this->assertTrue($resource['is_points_earn_cancelled'], $status->value.' 행이 「적립예정」으로 남는다');
        }
    }

    /**
     * 취소 전에 이미 적립된 옵션은 「적립완료」가 이긴다 (S1-D19 경계)
     *
     * 지연 적립 설정에서는 구매확정 후 적립된 다음 취소가 들어오는 시간차가 실제로
     * 있다(회수 전 한 찰나). 그 사이에는 「적립 없음」이 아니라 지금까지처럼
     * 「적립완료」를 말해야 한다 — 돈은 실제로 나갔기 때문이다.
     *
     * @scenario stock_deduction=not_deducted, points_earn=earned, non_purchase_tx=none
     *
     * @effects resource_marks_cancelled_option_as_earn_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_option_with_issued_earn_still_reads_as_earned(): void
    {
        // Given: 취소된 옵션 + 그러나 PURCHASE_EARN 은 이미 발행된 상태
        $user = $this->createUser();
        $order = OrderFactory::new()->create(['user_id' => $user->id]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_earned_points_amount' => 520,
            'option_status' => OrderStatusEnum::CANCELLED->value,
        ]);
        MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 520,
            'remaining_amount' => 520,
            'balance_after' => 520,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
        ]);

        // When
        $loaded = app(OrderRepositoryInterface::class)->findWithRelations($order->id);
        $resource = (new OrderOptionResource($loaded->options->first()))->resolve();

        // Then: 상위는 사실(지급됨) — 취소라는 이유로 지급 사실을 지우지 않는다
        $this->assertTrue($resource['is_points_earned']);
        $this->assertFalse($resource['is_points_earn_cancelled']);
    }

    /**
     * OrderResource 가 cancelled_at native 컬럼과 cancelled_at_formatted 를 노출하는지 확인.
     *
     * @effects order_exposes_cancelled_at_with_its_formatted_twin
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_exposes_cancelled_at_and_formatted(): void
    {
        $order = OrderFactory::new()->create(['cancelled_at' => now()]);

        $resource = (new OrderResource($order))->resolve();

        $this->assertArrayHasKey('cancelled_at', $resource);
        $this->assertArrayHasKey('cancelled_at_formatted', $resource);
        $this->assertNotNull($resource['cancelled_at']);
        $this->assertNotEmpty($resource['cancelled_at_formatted']);
    }

    /**
     * cancelled_at 이 null 이면 cancelled_at_formatted 도 null 이다.
     *
     * @effects order_cancelled_at_formatted_is_null_when_the_order_was_never_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_cancelled_at_formatted_null_when_not_cancelled(): void
    {
        $order = OrderFactory::new()->create(['cancelled_at' => null]);

        $resource = (new OrderResource($order))->resolve();

        $this->assertNull($resource['cancelled_at']);
        $this->assertNull($resource['cancelled_at_formatted']);
    }

    /**
     * OrderPaymentResource 가 결제수단별 requires_pg_cancellation 플래그를 노출하는지 확인 (A28).
     *
     * @effects payment_resource_declares_whether_the_method_requires_a_pg_cancellation, resource_exposes_requires_pg_cancellation
     *
     * @return void 반환값 없음
     */
    public function test_order_payment_resource_exposes_requires_pg_cancellation(): void
    {
        $order = OrderFactory::new()->create();

        $card = OrderPaymentFactory::new()
            ->forOrder($order)->create(['payment_method' => PaymentMethodEnum::CARD]);
        $cardResource = (new OrderPaymentResource($card))->resolve();
        $this->assertTrue($cardResource['requires_pg_cancellation'], '카드는 PG 취소 대상');

        $dbankOrder = OrderFactory::new()->create();
        $dbank = OrderPaymentFactory::new()
            ->forOrder($dbankOrder)->create(['payment_method' => PaymentMethodEnum::DBANK]);
        $dbankResource = (new OrderPaymentResource($dbank))->resolve();
        $this->assertFalse($dbankResource['requires_pg_cancellation'], '무통장은 PG 취소 대상 아님');
    }

    /**
     * OrderResource 의 is_partially_cancelled 파생 플래그가 "취소 옵션 ∧ 잔여 활성 옵션" 일 때만 true 인지 확인.
     * (부분취소는 별도 order_status 가 아니라 파생 플래그로 표시 — 2026-06-22.)
     *
     * @effects order_is_partially_cancelled_only_with_both_cancelled_and_active_options
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_exposes_is_partially_cancelled_flag(): void
    {
        // 일부만 취소: 활성 1 + 취소 1 → true
        $partial = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        OrderOptionFactory::new()->forOrder($partial)->create(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        OrderOptionFactory::new()->forOrder($partial)->create(['option_status' => OrderStatusEnum::CANCELLED->value]);
        $partial->load('options');
        $this->assertTrue((new OrderResource($partial))->resolve()['is_partially_cancelled']);

        // 취소 없음: 활성 2 → false
        $normal = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        OrderOptionFactory::new()->forOrder($normal)->create(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        OrderOptionFactory::new()->forOrder($normal)->create(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $normal->load('options');
        $this->assertFalse((new OrderResource($normal))->resolve()['is_partially_cancelled']);

        // 전부 취소(전체취소): 활성 0 → false (부분취소 아님)
        $full = OrderFactory::new()->create(['order_status' => OrderStatusEnum::CANCELLED->value]);
        OrderOptionFactory::new()->forOrder($full)->create(['option_status' => OrderStatusEnum::CANCELLED->value]);
        OrderOptionFactory::new()->forOrder($full)->create(['option_status' => OrderStatusEnum::CANCELLED->value]);
        $full->load('options');
        $this->assertFalse((new OrderResource($full))->resolve()['is_partially_cancelled']);
    }

    /**
     * 구매확정 후 작성 기한(기본 90일)이 지나면 review_deadline_passed=true
     *
     * 버튼 노출 판정(Resource)이 제출 검증(ProductReviewService::canWrite)과
     * 동일한 경계(confirmed_at + N일)를 사용함을 회귀로 고정한다.
     *
     * @effects option_review_deadline_passed_after_the_write_period_from_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_review_deadline_passed_true_when_expired(): void
    {
        // Given: 구매확정 + 미작성 + 확정 100일 경과 (기본 기한 90일 초과)
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now()->subDays(100),
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: 기한 만료 안내 노출, 작성 버튼은 미노출
        $this->assertTrue($resource['review_deadline_passed']);
        $this->assertFalse($resource['can_write_review']);
    }

    /**
     * 구매확정 후 작성 기한 이내(확정 직후)면 review_deadline_passed=false
     *
     * @effects option_review_deadline_not_passed_inside_the_write_period
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_review_deadline_passed_false_within_period(): void
    {
        // Given: 구매확정 + 미작성 + 확정 1일 경과 (기한 이내)
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now()->subDays(1),
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: 기한 이내이므로 만료 안내 미노출
        $this->assertFalse($resource['review_deadline_passed']);
    }

    /**
     * 구매확정 전(미확정) 옵션은 기한 만료 안내를 노출하지 않는다
     *
     * @effects option_review_deadline_not_passed_before_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_order_option_resource_review_deadline_passed_false_when_not_confirmed(): void
    {
        // Given: 배송완료(미확정) + confirmed_at 없음
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::DELIVERED,
            'confirmed_at' => null,
        ]);

        // When: 리소스 변환
        $resource = (new OrderOptionResource($option))->resolve();

        // Then: 미확정 상태에서는 만료 안내 미노출
        $this->assertFalse($resource['review_deadline_passed']);
    }

    /**
     * 과거 주문의 *_formatted 는 주문 시점 스냅샷 통화로 표기되며, 현재 기본 통화 설정 변경에 영향받지 않는다.
     *
     * 주문이 USD 기준으로 기록됐다면, 운영자가 이후 기본 통화를 KRW 로 바꿔도 그 주문의 합계 표기는
     * 계속 "$..." 로 고정되어야 한다 (소급 통화 변경 방지).
     *
     * @effects order_formatted_amounts_follow_the_order_snapshot_currency_not_the_current_default
     *
     * @return void 반환값 없음
     */
    public function test_order_amount_formatted_uses_snapshot_currency_not_current_default(): void
    {
        // Given: USD 기준으로 기록된 주문 (currency_snapshot.base_currency = USD)
        $order = OrderFactory::new()->create([
            'currency' => 'USD',
            'currency_snapshot' => ['base_currency' => 'USD', 'order_currency' => 'USD'],
            'total_amount' => 50,
            'subtotal_amount' => 50,
        ]);

        // 현재 시스템 기본 통화는 KRW (기본값) 이지만, 주문 표기는 스냅샷 USD 를 따라야 한다.
        $resource = (new OrderResource($order))->resolve();

        // Then: 합계가 USD 기호($)로 표기되고, 원화(원)로 표기되지 않는다.
        $this->assertStringContainsString('$', $resource['total_amount_formatted'], '주문 표기는 스냅샷 통화(USD) 기호여야 합니다.');
        $this->assertStringNotContainsString('원', $resource['total_amount_formatted'], '기본 통화가 KRW 라도 USD 주문이 원화로 표기되면 안 됩니다.');
        $this->assertSame('$50.00', $resource['total_amount_formatted']);
    }

    /**
     * 자식 리소스(OrderPayment)의 base 통화 환산 필드는 주입된 주문 시점 통화로 포맷되고,
     * 미주입 시 현재 기본 통화로 폴백한다.
     *
     * 반면 `paid_amount_local` 은 PG 가 **실제 청구한 결제 통화** 금액이므로 주입값과 무관하게
     * 결제 행의 통화(`payment.currency`)로 포맷된다. 주입 통화로 표기하면 base≠결제 통화 주문에서
     * 숫자와 기호의 단위가 어긋난다 (#421 기본통화≠결제통화 정합).
     *
     * @effects child_resource_formats_base_fields_with_the_injected_order_currency, child_resource_formats_the_actually_charged_amount_with_the_payment_currency
     *
     * @return void 반환값 없음
     */
    public function test_child_resource_uses_injected_order_currency(): void
    {
        // Given: 결제 (paid_amount_local = 50, 결제 통화는 팩토리 기본 = KRW)
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'paid_amount_local' => 50,
            'vat_amount' => 0,
        ]);

        // When: USD 를 주입해 직렬화 (부모 → 자식 전파 경로)
        $injected = (new OrderPaymentResource($payment))->withOrderCurrency('USD')->resolve();

        // Then: base 환산 필드는 주입 통화(USD) 기호로 표기
        $this->assertStringContainsString('$', $injected['vat_amount_formatted']);
        $this->assertStringNotContainsString('원', $injected['vat_amount_formatted']);

        // 실청구액은 결제 행 통화(KRW)를 그대로 따른다 — 주입에 흔들리지 않는다.
        $this->assertSame('50원', $injected['paid_amount_formatted']);
        $this->assertSame('KRW', $injected['payment_currency']);

        // 미주입 시: base 환산 필드도 현재 기본 통화(KRW)로 폴백
        $fallback = (new OrderPaymentResource($payment))->resolve();
        $this->assertStringContainsString('원', $fallback['vat_amount_formatted']);
        $this->assertSame('50원', $fallback['paid_amount_formatted']);
    }

    /**
     * base 통화와 결제 통화가 다를 때, 결제 통화 기준 실청구액을 함께 내려야 한다.
     *
     * 서버의 입금·PG 금액 검증 SSoT 는 `resolveOrderPaymentChargeAmount()`(결제 통화 최소단위)다.
     * 화면이 base 금액(`total_due_amount`)을 입금액으로 보내면 base≠결제 통화인 주문에서
     * **항상** 금액 불일치 422 가 되어 운영자가 입금확인을 할 수 없다.
     * 화면이 서버와 같은 값을 들 수 있도록 응답에 그 값을 노출한다.
     *
     * @effects order_exposes_the_payment_currency_charge_amount_for_cross_currency_orders
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_exposes_payment_currency_charge_amount(): void
    {
        // base JPY 31,000 / 결제 통화 KRW (base_unit 100, rate 1) → 청구액 310 KRW
        $order = OrderFactory::new()->create([
            'total_amount' => 31000,
            'total_due_amount' => 31000,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'JPY',
                'order_currency' => 'KRW',
                'exchange_rate' => 1,
                'base_unit' => 100,
                'exchange_rates' => [
                    'JPY' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'round', 'decimal_places' => 0, 'base_unit' => 100],
                    'KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                ],
            ],
        ]);

        $expected = app(CurrencyConversionService::class)
            ->resolveOrderPaymentChargeAmount($order);

        $resource = (new OrderResource($order))->resolve();

        $this->assertSame('KRW', $resource['payment_currency']);
        $this->assertTrue($resource['is_cross_currency']);

        // 서버 검증 기준과 정확히 같은 값
        $this->assertSame($expected, $resource['total_due_charge_amount']);
        $this->assertSame(310, $resource['total_due_charge_amount']);

        // 표시는 결제 통화 기호로
        $this->assertStringContainsString('원', $resource['total_due_charge_amount_formatted']);

        // base 금액은 종전대로 유지 (기존 소비처 무영향)
        $this->assertSame(31000, (int) $resource['total_due_amount']);
    }

    /**
     * base == 결제 통화면 청구액이 base 금액과 같아야 한다 (단일통화 상점 회귀 방지).
     *
     * @effects order_charge_amount_equals_the_base_amount_for_single_currency_orders
     *
     * @return void 반환값 없음
     */
    public function test_order_resource_charge_amount_equals_base_when_single_currency(): void
    {
        $order = OrderFactory::new()->create([
            'total_amount' => 12000,
            'total_due_amount' => 12000,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'exchange_rate' => 1,
                'base_unit' => 1,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1],
                ],
            ],
        ]);

        $resource = (new OrderResource($order))->resolve();

        $this->assertFalse($resource['is_cross_currency']);
        $this->assertSame(12000, $resource['total_due_charge_amount']);
    }

    /**
     * 주문 상세는 현금 환불 중 마일리지로 지급한 몫을 따로 싣는다 — 「환불금액」 줄의 그중 내역(Q3).
     *
     * @effects order_detail_exposes_the_refunded_mileage_credit_total
     *
     * @return void 반환값 없음
     */
    public function test_refunded_mileage_credit_total_is_exposed(): void
    {
        $order = OrderFactory::new()->create(['currency' => 'KRW', 'total_refunded_amount' => 15000]);
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::CARD->value,
            'currency' => 'KRW',
        ]);

        foreach ([[RefundMethodEnum::POINTS, 10000], [RefundMethodEnum::PG, 5000]] as [$method, $amount]) {
            OrderRefund::create([
                'order_id' => $order->id,
                'refund_source_type' => RefundSourceTypeEnum::DIRECT,
                'refund_number' => 'RF-'.uniqid(),
                'refund_status' => RefundStatusEnum::COMPLETED,
                'refund_method' => $method,
                'refund_amount' => $amount,
                'refund_points_amount' => 0,
                'refund_shipping_amount' => 0,
            ]);
        }

        $resource = (new OrderResource($order->fresh('payment')))->resolve();

        $this->assertSame(10000, (int) $resource['total_refunded_mileage_credit_amount']);
        $this->assertStringContainsString('10,000', $resource['total_refunded_mileage_credit_amount_formatted']);
    }
}
