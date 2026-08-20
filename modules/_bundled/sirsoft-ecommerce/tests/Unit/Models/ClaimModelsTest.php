<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Illuminate\Database\QueryException;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimImageFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ShippingPolicyClaimAddressFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임(교환/반품/첨부/추가결제/회수지) 모델 테스트
 */
class ClaimModelsTest extends ModuleTestCase
{
    // ========================================
    // OrderExchange
    // ========================================

    public function test_exchange_can_be_created_and_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        $this->assertDatabaseHas('ecommerce_order_exchanges', [
            'id' => $exchange->id,
            'order_id' => $order->id,
        ]);
        $this->assertInstanceOf(Order::class, $exchange->order);
        $this->assertEquals($order->id, $exchange->order->id);
    }

    public function test_exchange_casts_enums_and_json_columns(): void
    {
        $exchange = OrderExchangeFactory::new()->create([
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'items_snapshot' => ['options' => [['id' => 1, 'quantity' => 2]]],
            'mc_claim_shipping_fee' => ['USD' => 2.5],
        ]);

        $fresh = OrderExchange::find($exchange->id);

        $this->assertInstanceOf(ExchangeStatusEnum::class, $fresh->exchange_status);
        $this->assertInstanceOf(ClaimReasonFaultTypeEnum::class, $fresh->fault_type);
        $this->assertInstanceOf(ClaimChargeMethodEnum::class, $fresh->charge_method);
        $this->assertInstanceOf(ClaimShippingFeeModeEnum::class, $fresh->shipping_fee_mode);
        $this->assertIsArray($fresh->items_snapshot);
        $this->assertSame(2, $fresh->items_snapshot['options'][0]['quantity']);
        $this->assertSame(2.5, $fresh->mc_claim_shipping_fee['USD']);
    }

    public function test_exchange_charge_method_is_nullable_for_zero_charge(): void
    {
        $exchange = OrderExchangeFactory::new()->create([
            'charge_method' => null,
            'total_charge_amount' => 0,
        ]);

        $this->assertNull($exchange->charge_method);
        $this->assertFalse($exchange->requiresAdditionalPayment());
    }

    public function test_exchange_requires_additional_payment_only_for_payment_methods(): void
    {
        $payable = OrderExchangeFactory::new()->create([
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            'total_charge_amount' => 3000,
        ]);
        $this->assertTrue($payable->requiresAdditionalPayment());

        // 환불 차감은 환불 실행 시점에 상계되므로 별도 결제 건이 생기지 않는다.
        $deducted = OrderExchangeFactory::new()->create([
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'total_charge_amount' => 3000,
        ]);
        $this->assertFalse($deducted->requiresAdditionalPayment());
    }

    public function test_exchange_status_helpers_delegate_to_enum(): void
    {
        $requested = OrderExchangeFactory::new()->status(ExchangeStatusEnum::REQUESTED)->create();
        $this->assertTrue($requested->isWithdrawable());
        $this->assertFalse($requested->isFinal());

        $completed = OrderExchangeFactory::new()->status(ExchangeStatusEnum::COMPLETED)->create();
        $this->assertFalse($completed->isWithdrawable());
        $this->assertTrue($completed->isFinal());
    }

    public function test_exchange_guest_request_is_detected_by_null_requester(): void
    {
        $guest = OrderExchangeFactory::new()->guest()->create();
        $this->assertTrue($guest->isGuestRequest());

        $member = OrderExchangeFactory::new()->create(['requested_by' => $this->createUser()->id]);
        $this->assertFalse($member->isGuestRequest());
    }

    public function test_exchange_claim_type_is_exchange(): void
    {
        $this->assertSame(ClaimTypeEnum::EXCHANGE, OrderExchangeFactory::new()->create()->claimType());
    }

    public function test_order_has_many_exchanges(): void
    {
        $order = OrderFactory::new()->create();
        OrderExchangeFactory::new()->count(2)->create(['order_id' => $order->id]);

        $this->assertCount(2, $order->refresh()->exchanges);
    }

    // ========================================
    // OrderExchangeOption
    // ========================================

    public function test_exchange_option_belongs_to_exchange_and_order_option(): void
    {
        $order = OrderFactory::new()->create();
        $orderOption = OrderOptionFactory::new()->forOrder($order)->create();
        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        $option = OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
        ]);

        $this->assertEquals($exchange->id, $option->orderExchange->id);
        $this->assertEquals($orderOption->id, $option->orderOption->id);
        $this->assertCount(1, $exchange->refresh()->exchangeOptions);
    }

    public function test_exchange_option_price_difference_sign_drives_charge_or_refund(): void
    {
        $higher = OrderExchangeOptionFactory::new()->higherPrice(5000)->create();
        $this->assertTrue($higher->hasAdditionalCharge());
        $this->assertFalse($higher->hasRefundableDifference());

        $lower = OrderExchangeOptionFactory::new()->lowerPrice(5000)->create();
        $this->assertTrue($lower->hasRefundableDifference());
        $this->assertFalse($lower->hasAdditionalCharge());

        $equal = OrderExchangeOptionFactory::new()->create();
        $this->assertFalse($equal->hasAdditionalCharge());
        $this->assertFalse($equal->hasRefundableDifference());
    }

    public function test_exchange_option_partial_quantity_detection(): void
    {
        $partial = OrderExchangeOptionFactory::new()->partialQuantity(1, 3)->create();
        $this->assertTrue($partial->isPartialQuantity());

        $full = OrderExchangeOptionFactory::new()->create();
        $this->assertFalse($full->isPartialQuantity());
    }

    public function test_exchange_option_status_is_cast_to_exchange_status_enum(): void
    {
        $option = OrderExchangeOptionFactory::new()->create([
            'option_status' => ExchangeStatusEnum::COLLECTED,
        ]);

        $this->assertInstanceOf(ExchangeStatusEnum::class, OrderExchangeOption::find($option->id)->option_status);
    }

    // ========================================
    // OrderReturn
    // ========================================

    public function test_return_can_be_created_and_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id]);

        $this->assertDatabaseHas('ecommerce_order_returns', [
            'id' => $return->id,
            'order_id' => $order->id,
        ]);
        $this->assertInstanceOf(ReturnStatusEnum::class, $return->return_status);
        $this->assertSame(ClaimTypeEnum::RETURN, $return->claimType());
    }

    public function test_return_options_are_related_and_carry_subtotal(): void
    {
        $order = OrderFactory::new()->create();
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id]);
        $orderOption = OrderOptionFactory::new()->forOrder($order)->create();

        OrderReturnOptionFactory::new()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'unit_price' => 10000,
            'return_quantity' => 2,
            'subtotal_amount' => 20000,
        ]);

        $options = $return->refresh()->returnOptions;
        $this->assertCount(1, $options);
        $this->assertEquals(20000, (float) $options->first()->subtotal_amount);
    }

    public function test_return_links_to_refund_request_when_completed(): void
    {
        $order = OrderFactory::new()->create();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-TEST-0001',
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'refund_amount' => 10000,
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'refund_id' => $refund->id,
        ]);

        $this->assertInstanceOf(OrderRefund::class, $return->refund);
        $this->assertEquals($refund->id, $return->refund->id);
    }

    public function test_order_has_many_returns(): void
    {
        $order = OrderFactory::new()->create();
        OrderReturnFactory::new()->count(3)->create(['order_id' => $order->id]);

        $this->assertCount(3, $order->refresh()->returns);
    }

    // ========================================
    // OrderRefund 클레임 확장
    // ========================================

    public function test_refund_source_type_is_cast_and_resolves_source_record(): void
    {
        $order = OrderFactory::new()->create();
        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-TEST-0001',
            'cancel_type' => 'full',
            'cancel_status' => 'requested',
            'cancel_reason_type' => 'changed_mind',
            'items_snapshot' => [],
        ]);

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL->value,
            'refund_source_id' => $cancel->id,
            'refund_number' => 'RF-TEST-0002',
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'refund_amount' => 5000,
        ]);

        $fresh = OrderRefund::find($refund->id);
        $this->assertInstanceOf(RefundSourceTypeEnum::class, $fresh->refund_source_type);
        $this->assertInstanceOf(OrderCancel::class, $fresh->resolveRefundSource());
        $this->assertEquals($cancel->id, $fresh->resolveRefundSource()->id);
    }

    public function test_refund_source_direct_resolves_to_null_without_error(): void
    {
        // 'direct' 를 다형 관계로 두면 Eloquent 가 클래스명으로 해석하다 fatal 이 난다.
        // 명시 해석기가 그 값을 null 로 다루는지 고정한다.
        $order = OrderFactory::new()->create();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT->value,
            'refund_source_id' => null,
            'refund_number' => 'RF-TEST-0003',
            'refund_status' => 'requested',
            'refund_method' => 'bank',
            'refund_amount' => 1000,
        ]);

        $this->assertNull(OrderRefund::find($refund->id)->resolveRefundSource());
    }

    public function test_refund_source_resolves_return_and_exchange(): void
    {
        $order = OrderFactory::new()->create();
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id]);
        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        $fromReturn = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN->value,
            'refund_source_id' => $return->id,
            'refund_number' => 'RF-TEST-0004',
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'refund_amount' => 1000,
        ]);
        $fromExchange = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::EXCHANGE->value,
            'refund_source_id' => $exchange->id,
            'refund_number' => 'RF-TEST-0005',
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'refund_amount' => 1000,
        ]);

        $this->assertInstanceOf(OrderReturn::class, $fromReturn->resolveRefundSource());
        $this->assertInstanceOf(OrderExchange::class, $fromExchange->resolveRefundSource());
    }

    public function test_refund_legacy_cancel_link_is_preserved_alongside_source(): void
    {
        // order_cancel_id 는 하위호환으로 유지된다 — 이중 기록이 계약이다.
        $order = OrderFactory::new()->create();
        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-TEST-0002',
            'cancel_type' => 'partial',
            'cancel_status' => 'requested',
            'cancel_reason_type' => 'changed_mind',
            'items_snapshot' => [],
        ]);
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL->value,
            'refund_source_id' => $cancel->id,
            'refund_number' => 'RF-TEST-0006',
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'refund_amount' => 1000,
        ]);

        $this->assertEquals($cancel->id, $refund->orderCancel->id);
    }

    // ========================================
    // ClaimImage (다형 첨부)
    // ========================================

    public function test_claim_image_generates_hash_on_create(): void
    {
        $image = ClaimImageFactory::new()->create();

        $this->assertNotEmpty($image->hash);
        $this->assertSame(12, strlen($image->hash));
    }

    public function test_claim_image_morphs_to_exchange_and_return(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        $return = OrderReturnFactory::new()->create();

        $onExchange = ClaimImageFactory::new()->forExchange($exchange)->create();
        $onReturn = ClaimImageFactory::new()->forReturn($return)->create();

        $this->assertInstanceOf(OrderExchange::class, $onExchange->claimable);
        $this->assertEquals($exchange->id, $onExchange->claimable->id);
        $this->assertInstanceOf(OrderReturn::class, $onReturn->claimable);
        $this->assertEquals($return->id, $onReturn->claimable->id);
    }

    public function test_claim_image_stores_short_claim_type_not_fqcn(): void
    {
        // 저장값이 FQCN 이면 네임스페이스 변경 시 기존 행이 깨진다.
        $image = ClaimImageFactory::new()->forExchange()->create();

        $this->assertSame(ClaimTypeEnum::EXCHANGE->value, $image->claimable_type);
        $this->assertSame(ClaimTypeEnum::EXCHANGE, $image->claimType());
    }

    public function test_claim_owner_loads_images_in_sort_order(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        ClaimImageFactory::new()->forExchange($exchange)->create(['sort_order' => 2]);
        ClaimImageFactory::new()->forExchange($exchange)->create(['sort_order' => 0]);
        ClaimImageFactory::new()->forExchange($exchange)->create(['sort_order' => 1]);

        $sortOrders = $exchange->refresh()->images->pluck('sort_order')->all();

        $this->assertSame([0, 1, 2], $sortOrders);
    }

    public function test_claim_image_scopes_filter_by_type_and_order(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        $return = OrderReturnFactory::new()->create();
        ClaimImageFactory::new()->forExchange($exchange)->create();
        ClaimImageFactory::new()->forReturn($return)->count(2)->create();

        $this->assertCount(1, ClaimImage::ofClaimType(ClaimTypeEnum::EXCHANGE)->ordered()->get());
        $this->assertCount(2, ClaimImage::ofClaimType(ClaimTypeEnum::RETURN)->ordered()->get());
    }

    // ========================================
    // ClaimPayment (다형 추가결제)
    // ========================================

    public function test_claim_payment_totals_pg_and_points(): void
    {
        $payment = ClaimPaymentFactory::new()->create([
            'amount' => 3000,
            'points_amount' => 2000,
        ]);

        $this->assertEquals(5000.0, $payment->getTotalAmount());
        $this->assertTrue($payment->usesPoints());
    }

    public function test_claim_payment_paid_state_flags(): void
    {
        $pending = ClaimPaymentFactory::new()->create();
        $this->assertFalse($pending->isPaid());
        $this->assertFalse($pending->isRefundable());

        $paid = ClaimPaymentFactory::new()->paid()->create();
        $this->assertInstanceOf(ClaimPaymentStatusEnum::class, $paid->payment_status);
        $this->assertTrue($paid->isPaid());
        $this->assertTrue($paid->isRefundable());
    }

    public function test_claim_payment_morphs_to_exchange_and_return(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        $return = OrderReturnFactory::new()->create();

        $onExchange = ClaimPaymentFactory::new()->create([
            'payable_id' => $exchange->id,
            'order_id' => $exchange->order_id,
        ]);
        $onReturn = ClaimPaymentFactory::new()->forReturn($return)->create([
            'order_id' => $return->order_id,
        ]);

        $this->assertInstanceOf(OrderExchange::class, $onExchange->payable);
        $this->assertInstanceOf(OrderReturn::class, $onReturn->payable);
        $this->assertCount(1, $exchange->refresh()->claimPayments);
        $this->assertCount(1, $return->refresh()->claimPayments);
    }

    public function test_claim_payment_number_is_unique(): void
    {
        $first = ClaimPaymentFactory::new()->create();

        $this->expectException(QueryException::class);
        ClaimPaymentFactory::new()->create(['payment_number' => $first->payment_number]);
    }

    public function test_claim_payment_status_scope_filters(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        ClaimPaymentFactory::new()->create(['payable_id' => $exchange->id, 'order_id' => $exchange->order_id]);
        ClaimPaymentFactory::new()->paid()->create(['payable_id' => $exchange->id, 'order_id' => $exchange->order_id]);

        $this->assertCount(1, ClaimPayment::ofStatus(ClaimPaymentStatusEnum::PAID)->get());
        $this->assertCount(1, ClaimPayment::ofStatus(ClaimPaymentStatusEnum::PENDING)->get());
    }

    public function test_order_has_many_claim_payments(): void
    {
        $exchange = OrderExchangeFactory::new()->create();
        ClaimPaymentFactory::new()->count(2)->create([
            'payable_id' => $exchange->id,
            'order_id' => $exchange->order_id,
        ]);

        $this->assertCount(2, Order::find($exchange->order_id)->claimPayments);
    }

    // ========================================
    // ShippingPolicyClaimAddress
    // ========================================

    public function test_claim_address_is_unique_per_policy_and_type(): void
    {
        $address = ShippingPolicyClaimAddressFactory::new()->forReturn()->create();

        $this->expectException(QueryException::class);
        ShippingPolicyClaimAddressFactory::new()->forReturn()->create([
            'shipping_policy_id' => $address->shipping_policy_id,
        ]);
    }

    public function test_claim_address_allows_one_per_claim_type(): void
    {
        $address = ShippingPolicyClaimAddressFactory::new()->forReturn()->create();
        ShippingPolicyClaimAddressFactory::new()->forExchange()->create([
            'shipping_policy_id' => $address->shipping_policy_id,
        ]);

        $policy = $address->shippingPolicy;
        $this->assertCount(2, $policy->claimAddresses);
        $this->assertNotNull($policy->getClaimAddress(ClaimTypeEnum::EXCHANGE));
        $this->assertNotNull($policy->getClaimAddress(ClaimTypeEnum::RETURN));
    }

    public function test_claim_address_snapshot_carries_delivery_fields(): void
    {
        $address = ShippingPolicyClaimAddressFactory::new()->forExchange()->create([
            'recipient_name' => '회수 담당자',
            'zipcode' => '06236',
            'address1' => '서울특별시 강남구 테헤란로 123',
            'address2' => '4층',
        ]);

        $snapshot = $address->toSnapshot();

        $this->assertSame('회수 담당자', $snapshot['recipient_name']);
        $this->assertSame('06236', $snapshot['zipcode']);
        $this->assertSame('4층', $snapshot['address2']);
        $this->assertSame('KR', $snapshot['country_code']);
        $this->assertArrayNotHasKey('id', $snapshot, '스냅샷은 정책 행 ID 를 들고 가지 않는다');
    }

    public function test_claim_address_type_is_cast_to_claim_type_enum(): void
    {
        $address = ShippingPolicyClaimAddressFactory::new()->forExchange()->create();

        $this->assertInstanceOf(
            ClaimTypeEnum::class,
            ShippingPolicyClaimAddress::find($address->id)->claim_type
        );
    }
}
