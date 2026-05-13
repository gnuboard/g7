<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderTaxInvoiceFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * Order 모델 테스트
 */
class OrderTest extends ModuleTestCase
{
    public function test_order_can_be_created(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'TEST-ORD-001',
            'order_status' => OrderStatusEnum::PENDING_PAYMENT->value,
        ]);

        $this->assertDatabaseHas('ecommerce_orders', [
            'id' => $order->id,
            'order_number' => 'TEST-ORD-001',
        ]);
    }

    public function test_order_has_options_relationship(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->count(3)->create();

        $this->assertCount(3, $order->fresh()->options);
        $this->assertInstanceOf(OrderOption::class, $order->options->first());
    }

    public function test_order_has_shipping_address_relationship(): void
    {
        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'orderer_name' => '홍길동',
            'recipient_name' => '김철수',
        ]);

        $address = $order->fresh()->shippingAddress;

        $this->assertNotNull($address);
        $this->assertInstanceOf(OrderAddress::class, $address);
        $this->assertEquals('홍길동', $address->orderer_name);
    }

    public function test_order_has_billing_address_relationship(): void
    {
        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order)->billing()->create();

        $address = $order->fresh()->billingAddress;

        $this->assertNotNull($address);
        $this->assertEquals('billing', $address->address_type);
    }

    public function test_order_has_payment_relationship(): void
    {
        $order = OrderFactory::new()->create();
        OrderPaymentFactory::new()->forOrder($order)->card()->create();

        $payment = $order->fresh()->payment;

        $this->assertNotNull($payment);
        $this->assertInstanceOf(OrderPayment::class, $payment);
    }

    public function test_order_has_shippings_relationship(): void
    {
        $order = OrderFactory::new()->create();
        OrderShippingFactory::new()->forOrder($order)->count(2)->create();

        $this->assertCount(2, $order->fresh()->shippings);
        $this->assertInstanceOf(OrderShipping::class, $order->shippings->first());
    }

    public function test_order_has_tax_invoices_relationship(): void
    {
        $order = OrderFactory::new()->create();
        OrderTaxInvoiceFactory::new()->forOrder($order)->create();

        $this->assertCount(1, $order->fresh()->taxInvoices);
        $this->assertInstanceOf(OrderTaxInvoice::class, $order->taxInvoices->first());
    }

    public function test_get_orderer_name_returns_orderer_name(): void
    {
        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'orderer_name' => '주문자명',
        ]);

        $this->assertEquals('주문자명', $order->fresh()->getOrdererName());
    }

    public function test_get_orderer_name_returns_null_without_address(): void
    {
        $order = OrderFactory::new()->create();

        $this->assertNull($order->getOrdererName());
    }

    public function test_get_recipient_name_returns_recipient_name(): void
    {
        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'recipient_name' => '수령인명',
        ]);

        $this->assertEquals('수령인명', $order->fresh()->getRecipientName());
    }

    public function test_get_first_product_name_returns_first_option_product_name(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['product_name' => '첫번째 상품']);
        OrderOptionFactory::new()->forOrder($order)->create(['product_name' => '두번째 상품']);

        $this->assertEquals('첫번째 상품', $order->fresh()->getFirstProductName());
    }

    public function test_get_first_product_name_returns_null_without_options(): void
    {
        $order = OrderFactory::new()->create();

        $this->assertNull($order->getFirstProductName());
    }

    public function test_get_product_summary_returns_single_product_name(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['product_name' => '단일 상품']);

        $this->assertEquals('단일 상품', $order->fresh()->getProductSummary());
    }

    public function test_get_product_summary_returns_formatted_summary_for_multiple(): void
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create(['product_name' => '첫번째 상품']);
        OrderOptionFactory::new()->forOrder($order)->create(['product_name' => '두번째 상품']);
        OrderOptionFactory::new()->forOrder($order)->create(['product_name' => '세번째 상품']);

        $summary = $order->fresh()->getProductSummary();

        $this->assertEquals('첫번째 상품 외 2건', $summary);
    }

    public function test_get_product_summary_returns_empty_string_without_options(): void
    {
        $order = OrderFactory::new()->create();

        $this->assertEquals('', $order->getProductSummary());
    }

    public function test_get_payment_method_label_returns_label(): void
    {
        $order = OrderFactory::new()->create();
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::CARD->value,
        ]);

        $label = $order->fresh()->getPaymentMethodLabel();

        $this->assertNotNull($label);
    }

    public function test_get_payment_method_label_returns_null_without_payment(): void
    {
        $order = OrderFactory::new()->create();

        $this->assertNull($order->getPaymentMethodLabel());
    }

    public function test_is_cancellable_returns_true_for_cancellable_statuses(): void
    {
        $cancellableStatuses = [
            OrderStatusEnum::PENDING_ORDER,
            OrderStatusEnum::PENDING_PAYMENT,
            OrderStatusEnum::PAYMENT_COMPLETE,
        ];

        foreach ($cancellableStatuses as $status) {
            $order = OrderFactory::new()->create(['order_status' => $status->value]);
            $this->assertTrue($order->isCancellable(), "상태 '{$status->value}'는 취소 가능해야 합니다.");
        }
    }

    public function test_is_cancellable_returns_false_for_non_cancellable_statuses(): void
    {
        $nonCancellableStatuses = [
            OrderStatusEnum::SHIPPING,
            OrderStatusEnum::DELIVERED,
            OrderStatusEnum::CANCELLED,
        ];

        foreach ($nonCancellableStatuses as $status) {
            $order = OrderFactory::new()->create(['order_status' => $status->value]);
            $this->assertFalse($order->isCancellable(), "상태 '{$status->value}'는 취소 불가능해야 합니다.");
        }
    }

    public function test_is_confirmable_returns_true_for_delivered_status(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::DELIVERED->value]);

        $this->assertTrue($order->isConfirmable());
    }

    public function test_is_confirmable_returns_false_for_non_delivered_statuses(): void
    {
        $nonConfirmableStatuses = [
            OrderStatusEnum::PENDING_PAYMENT,
            OrderStatusEnum::PAYMENT_COMPLETE,
            OrderStatusEnum::SHIPPING,
        ];

        foreach ($nonConfirmableStatuses as $status) {
            $order = OrderFactory::new()->create(['order_status' => $status->value]);
            $this->assertFalse($order->isConfirmable(), "상태 '{$status->value}'는 구매확정 불가능해야 합니다.");
        }
    }

    public function test_order_casts_order_status_to_enum(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);

        $this->assertInstanceOf(OrderStatusEnum::class, $order->order_status);
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
    }

    public function test_order_casts_amounts_to_decimal(): void
    {
        $order = OrderFactory::new()->create([
            'total_amount' => 50000.50,
            'subtotal_amount' => 45000.00,
        ]);

        $this->assertEquals('50000.50', $order->total_amount);
        $this->assertEquals('45000.00', $order->subtotal_amount);
    }

    public function test_order_casts_json_fields_to_array(): void
    {
        $order = OrderFactory::new()->create([
            'order_meta' => ['key' => 'value'],
            'promotions_applied_snapshot' => ['promo1', 'promo2'],
        ]);

        $this->assertIsArray($order->order_meta);
        $this->assertIsArray($order->promotions_applied_snapshot);
        $this->assertEquals('value', $order->order_meta['key']);
    }

    public function test_order_uses_soft_deletes(): void
    {
        $order = OrderFactory::new()->create();
        $orderId = $order->id;

        $order->delete();

        $this->assertSoftDeleted('ecommerce_orders', ['id' => $orderId]);
        $this->assertNull(Order::find($orderId));
        $this->assertNotNull(Order::withTrashed()->find($orderId));
    }

    // ========================================
    // resolveRouteBinding() 테스트
    // ========================================

    public function test_resolve_route_binding_by_numeric_id(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create();

        // When: 숫자 ID로 바인딩 해석
        $resolved = (new Order())->resolveRouteBinding($order->id);

        // Then: 동일 주문 반환
        $this->assertEquals($order->id, $resolved->id);
    }

    public function test_resolve_route_binding_by_order_number(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create(['order_number' => 'ORD-20260322-99999']);

        // When: order_number로 바인딩 해석
        $resolved = (new Order())->resolveRouteBinding('ORD-20260322-99999');

        // Then: 동일 주문 반환
        $this->assertEquals($order->id, $resolved->id);
        $this->assertEquals('ORD-20260322-99999', $resolved->order_number);
    }

    public function test_resolve_route_binding_by_explicit_field(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create(['order_number' => 'ORD-FIELD-TEST']);

        // When: 명시적 필드 지정으로 바인딩 해석
        $resolved = (new Order())->resolveRouteBinding('ORD-FIELD-TEST', 'order_number');

        // Then: 동일 주문 반환
        $this->assertEquals($order->id, $resolved->id);
    }

    public function test_resolve_route_binding_throws_for_nonexistent_order_number(): void
    {
        // Given: 존재하지 않는 주문번호

        // Then: ModelNotFoundException 발생
        $this->expectException(ModelNotFoundException::class);

        // When: 존재하지 않는 주문번호로 바인딩 해석
        (new Order())->resolveRouteBinding('ORD-NONEXISTENT-00000');
    }

    public function test_resolve_route_binding_throws_for_nonexistent_id(): void
    {
        // Given: 존재하지 않는 ID

        // Then: ModelNotFoundException 발생
        $this->expectException(ModelNotFoundException::class);

        // When: 존재하지 않는 ID로 바인딩 해석
        (new Order())->resolveRouteBinding(999999);
    }
}
