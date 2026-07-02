<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
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
     * OrderOptionResource에서 mc_subtotal_discount_amount 필드가 제거되었는지 확인
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
     * OrderResource에 total_list_price 필드가 스냅샷의 list_price * quantity 합계를 반환하는지 확인
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
        $this->assertEquals('2,400원', $resource['total_earned_points_amount_formatted']);
        $this->assertEquals(1500, $resource['total_refunded_points_amount']);
        $this->assertEquals('1,500원', $resource['total_refunded_points_amount_formatted']);
    }

    /**
     * OrderOptionResource의 final_amount가 할인+마일리지+예치금 모두 차감하는지 확인
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
     * OrderResource 가 cancelled_at native 컬럼과 cancelled_at_formatted 를 노출하는지 확인.
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
     * 자식 리소스(OrderPayment)는 주입된 주문 시점 통화로 포맷되고, 미주입 시 현재 기본 통화로 폴백한다.
     */
    public function test_child_resource_uses_injected_order_currency(): void
    {
        // Given: 결제 (paid_amount_local = 50)
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'paid_amount_local' => 50,
            'vat_amount' => 0,
        ]);

        // When: USD 를 주입해 직렬화 (부모 → 자식 전파 경로)
        $injected = (new OrderPaymentResource($payment))->withOrderCurrency('USD')->resolve();

        // Then: 주입 통화(USD) 기호로 표기
        $this->assertSame('$50.00', $injected['paid_amount_formatted']);
        $this->assertStringNotContainsString('원', $injected['paid_amount_formatted']);

        // 미주입 시: 현재 기본 통화(KRW)로 폴백
        $fallback = (new OrderPaymentResource($payment))->resolve();
        $this->assertSame('50원', $fallback['paid_amount_formatted']);
    }
}
