<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderDateTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\TaxInvoiceStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 관련 Enum 테스트
 */
class OrderEnumsTest extends ModuleTestCase
{
    // ========================================
    // OrderStatusEnum 테스트
    // ========================================

    public function test_order_status_has_correct_values(): void
    {
        $this->assertEquals('pending_order', OrderStatusEnum::PENDING_ORDER->value);
        $this->assertEquals('pending_payment', OrderStatusEnum::PENDING_PAYMENT->value);
        $this->assertEquals('payment_complete', OrderStatusEnum::PAYMENT_COMPLETE->value);
        $this->assertEquals('shipping_hold', OrderStatusEnum::SHIPPING_HOLD->value);
        $this->assertEquals('preparing', OrderStatusEnum::PREPARING->value);
        $this->assertEquals('shipping_ready', OrderStatusEnum::SHIPPING_READY->value);
        $this->assertEquals('shipping', OrderStatusEnum::SHIPPING->value);
        $this->assertEquals('delivered', OrderStatusEnum::DELIVERED->value);
        $this->assertEquals('confirmed', OrderStatusEnum::CONFIRMED->value);
        $this->assertEquals('cancelled', OrderStatusEnum::CANCELLED->value);
    }

    public function test_order_status_values_returns_all_values(): void
    {
        $values = OrderStatusEnum::values();

        $this->assertIsArray($values);
        $this->assertCount(11, $values);
        $this->assertContains('pending_order', $values);
        $this->assertContains('cancelled', $values);
    }

    public function test_order_status_to_select_options_returns_array(): void
    {
        $options = OrderStatusEnum::toSelectOptions();

        $this->assertIsArray($options);
        $this->assertCount(11, $options);

        // 각 옵션에 value와 label이 있는지 확인
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    public function test_order_status_variant_returns_correct_values(): void
    {
        $this->assertEquals('secondary', OrderStatusEnum::PENDING_ORDER->variant());
        $this->assertEquals('warning', OrderStatusEnum::PENDING_PAYMENT->variant());
        $this->assertEquals('info', OrderStatusEnum::PAYMENT_COMPLETE->variant());
        $this->assertEquals('primary', OrderStatusEnum::SHIPPING->variant());
        $this->assertEquals('success', OrderStatusEnum::DELIVERED->variant());
        $this->assertEquals('danger', OrderStatusEnum::CANCELLED->variant());
    }

    public function test_order_status_is_before_payment(): void
    {
        $this->assertTrue(OrderStatusEnum::PENDING_ORDER->isBeforePayment());
        $this->assertTrue(OrderStatusEnum::PENDING_PAYMENT->isBeforePayment());
        $this->assertFalse(OrderStatusEnum::PAYMENT_COMPLETE->isBeforePayment());
        $this->assertFalse(OrderStatusEnum::DELIVERED->isBeforePayment());
    }

    public function test_order_status_is_before_shipping(): void
    {
        $this->assertTrue(OrderStatusEnum::PENDING_ORDER->isBeforeShipping());
        $this->assertTrue(OrderStatusEnum::PAYMENT_COMPLETE->isBeforeShipping());
        $this->assertTrue(OrderStatusEnum::PREPARING->isBeforeShipping());
        $this->assertFalse(OrderStatusEnum::SHIPPING->isBeforeShipping());
        $this->assertFalse(OrderStatusEnum::DELIVERED->isBeforeShipping());
    }

    public function test_order_status_shipping_info_required_excludes_delivered(): void
    {
        $requiredStatuses = OrderStatusEnum::shippingInfoRequiredStatuses();

        $this->assertContains(OrderStatusEnum::SHIPPING_READY, $requiredStatuses);
        $this->assertContains(OrderStatusEnum::SHIPPING, $requiredStatuses);
        $this->assertNotContains(OrderStatusEnum::DELIVERED, $requiredStatuses, '배송완료는 배송정보 필수가 아님');
        $this->assertCount(2, $requiredStatuses);
    }

    public function test_delivered_does_not_require_shipping_info(): void
    {
        $this->assertFalse(OrderStatusEnum::DELIVERED->requiresShippingInfo());
    }

    public function test_shipping_requires_shipping_info(): void
    {
        $this->assertTrue(OrderStatusEnum::SHIPPING->requiresShippingInfo());
        $this->assertTrue(OrderStatusEnum::SHIPPING_READY->requiresShippingInfo());
    }

    public function test_order_status_is_completed(): void
    {
        $this->assertTrue(OrderStatusEnum::DELIVERED->isCompleted());
        $this->assertTrue(OrderStatusEnum::CONFIRMED->isCompleted());
        $this->assertFalse(OrderStatusEnum::SHIPPING->isCompleted());
        $this->assertFalse(OrderStatusEnum::CANCELLED->isCompleted());
    }

    // ========================================
    // OrderOptionStatusEnum → OrderStatusEnum 통일 확인
    // ========================================

    // ========================================
    // PaymentStatusEnum 테스트
    // ========================================

    public function test_payment_status_has_correct_values(): void
    {
        // 실제 Enum 값에 맞춤
        $this->assertEquals('ready', PaymentStatusEnum::READY->value);
        $this->assertEquals('in_progress', PaymentStatusEnum::IN_PROGRESS->value);
        $this->assertEquals('waiting_deposit', PaymentStatusEnum::WAITING_DEPOSIT->value);
        $this->assertEquals('paid', PaymentStatusEnum::PAID->value);
        $this->assertEquals('partial_cancelled', PaymentStatusEnum::PARTIAL_CANCELLED->value);
        $this->assertEquals('cancelled', PaymentStatusEnum::CANCELLED->value);
        $this->assertEquals('failed', PaymentStatusEnum::FAILED->value);
        $this->assertEquals('expired', PaymentStatusEnum::EXPIRED->value);
    }

    public function test_payment_status_values_returns_all_values(): void
    {
        $values = PaymentStatusEnum::values();

        $this->assertIsArray($values);
        $this->assertCount(8, $values);
        $this->assertContains('ready', $values);
        $this->assertContains('paid', $values);
        $this->assertContains('cancelled', $values);
    }

    public function test_payment_status_variant_returns_correct_values(): void
    {
        $this->assertEquals('secondary', PaymentStatusEnum::READY->variant());
        $this->assertEquals('info', PaymentStatusEnum::IN_PROGRESS->variant());
        $this->assertEquals('warning', PaymentStatusEnum::WAITING_DEPOSIT->variant());
        $this->assertEquals('success', PaymentStatusEnum::PAID->variant());
        $this->assertEquals('danger', PaymentStatusEnum::CANCELLED->variant());
        $this->assertEquals('danger', PaymentStatusEnum::FAILED->variant());
    }

    // ========================================
    // PaymentMethodEnum 테스트
    // ========================================

    public function test_payment_method_has_correct_values(): void
    {
        $this->assertEquals('card', PaymentMethodEnum::CARD->value);
        $this->assertEquals('vbank', PaymentMethodEnum::VBANK->value);
        $this->assertEquals('dbank', PaymentMethodEnum::DBANK->value);
        $this->assertEquals('bank', PaymentMethodEnum::BANK->value);
        $this->assertEquals('phone', PaymentMethodEnum::PHONE->value);
        $this->assertEquals('point', PaymentMethodEnum::POINT->value);
        $this->assertEquals('deposit', PaymentMethodEnum::DEPOSIT->value);
        $this->assertEquals('free', PaymentMethodEnum::FREE->value);
    }

    public function test_payment_method_values_returns_all_values(): void
    {
        $values = PaymentMethodEnum::values();

        $this->assertIsArray($values);
        $this->assertCount(8, $values);
        $this->assertContains('card', $values);
        $this->assertContains('dbank', $values);
        $this->assertContains('point', $values);
        $this->assertContains('free', $values);
    }

    public function test_payment_method_is_pg_payment(): void
    {
        // 실제 금전 결제 — DBANK 포함
        $this->assertTrue(PaymentMethodEnum::CARD->isPgPayment());
        $this->assertTrue(PaymentMethodEnum::VBANK->isPgPayment());
        $this->assertTrue(PaymentMethodEnum::DBANK->isPgPayment());
        $this->assertTrue(PaymentMethodEnum::BANK->isPgPayment());
        $this->assertTrue(PaymentMethodEnum::PHONE->isPgPayment());

        // 내부 처리
        $this->assertFalse(PaymentMethodEnum::POINT->isPgPayment());
        $this->assertFalse(PaymentMethodEnum::DEPOSIT->isPgPayment());
        $this->assertFalse(PaymentMethodEnum::FREE->isPgPayment());
    }

    public function test_payment_method_needs_pg_provider(): void
    {
        // PG사 연동 필요
        $this->assertTrue(PaymentMethodEnum::CARD->needsPgProvider());
        $this->assertTrue(PaymentMethodEnum::VBANK->needsPgProvider());
        $this->assertTrue(PaymentMethodEnum::BANK->needsPgProvider());
        $this->assertTrue(PaymentMethodEnum::PHONE->needsPgProvider());

        // PG사 연동 불필요 — DBANK는 수동 처리
        $this->assertFalse(PaymentMethodEnum::DBANK->needsPgProvider());
        $this->assertFalse(PaymentMethodEnum::POINT->needsPgProvider());
        $this->assertFalse(PaymentMethodEnum::DEPOSIT->needsPgProvider());
        $this->assertFalse(PaymentMethodEnum::FREE->needsPgProvider());
    }

    // ========================================
    // ShippingStatusEnum 테스트
    // ========================================

    public function test_shipping_status_has_correct_values(): void
    {
        // 실제 Enum 값에 맞춤
        $this->assertEquals('pending', ShippingStatusEnum::PENDING->value);
        $this->assertEquals('preparing', ShippingStatusEnum::PREPARING->value);
        $this->assertEquals('ready', ShippingStatusEnum::READY->value);
        $this->assertEquals('shipped', ShippingStatusEnum::SHIPPED->value);
        $this->assertEquals('in_transit', ShippingStatusEnum::IN_TRANSIT->value);
        $this->assertEquals('out_for_delivery', ShippingStatusEnum::OUT_FOR_DELIVERY->value);
        $this->assertEquals('delivered', ShippingStatusEnum::DELIVERED->value);
        $this->assertEquals('failed', ShippingStatusEnum::FAILED->value);
        $this->assertEquals('returned', ShippingStatusEnum::RETURNED->value);
    }

    public function test_shipping_status_values_returns_all_values(): void
    {
        $values = ShippingStatusEnum::values();

        $this->assertIsArray($values);
        $this->assertContains('pending', $values);
        $this->assertContains('preparing', $values);
        $this->assertContains('delivered', $values);
    }

    public function test_shipping_status_variant_returns_correct_values(): void
    {
        $this->assertEquals('secondary', ShippingStatusEnum::PENDING->variant());
        $this->assertEquals('info', ShippingStatusEnum::PREPARING->variant());
        $this->assertEquals('primary', ShippingStatusEnum::SHIPPED->variant());
        $this->assertEquals('success', ShippingStatusEnum::DELIVERED->variant());
        $this->assertEquals('danger', ShippingStatusEnum::FAILED->variant());
    }

    // ========================================
    // TaxInvoiceStatusEnum 테스트
    // ========================================

    public function test_tax_invoice_status_has_correct_values(): void
    {
        $this->assertEquals('pending', TaxInvoiceStatusEnum::PENDING->value);
        $this->assertEquals('processing', TaxInvoiceStatusEnum::PROCESSING->value);
        $this->assertEquals('issued', TaxInvoiceStatusEnum::ISSUED->value);
        $this->assertEquals('failed', TaxInvoiceStatusEnum::FAILED->value);
        $this->assertEquals('cancelled', TaxInvoiceStatusEnum::CANCELLED->value);
    }

    public function test_tax_invoice_status_values_returns_all_values(): void
    {
        $values = TaxInvoiceStatusEnum::values();

        $this->assertIsArray($values);
        $this->assertCount(5, $values);
        $this->assertContains('pending', $values);
        $this->assertContains('issued', $values);
    }

    public function test_tax_invoice_status_variant_returns_correct_values(): void
    {
        $this->assertEquals('warning', TaxInvoiceStatusEnum::PENDING->variant());
        $this->assertEquals('info', TaxInvoiceStatusEnum::PROCESSING->variant());
        $this->assertEquals('success', TaxInvoiceStatusEnum::ISSUED->variant());
        $this->assertEquals('danger', TaxInvoiceStatusEnum::FAILED->variant());
        $this->assertEquals('secondary', TaxInvoiceStatusEnum::CANCELLED->variant());
    }

    // ========================================
    // DeviceTypeEnum 테스트
    // ========================================

    public function test_device_type_has_correct_values(): void
    {
        $values = DeviceTypeEnum::values();

        $this->assertIsArray($values);
        $this->assertContains('pc', $values);
        $this->assertContains('mobile', $values);
        $this->assertContains('app_ios', $values);
        $this->assertContains('app_android', $values);
        $this->assertContains('admin', $values);
        $this->assertContains('api', $values);
    }

    public function test_device_type_is_mobile(): void
    {
        $this->assertTrue(DeviceTypeEnum::MOBILE->isMobile());
        $this->assertTrue(DeviceTypeEnum::APP_IOS->isMobile());
        $this->assertTrue(DeviceTypeEnum::APP_ANDROID->isMobile());
        $this->assertFalse(DeviceTypeEnum::PC->isMobile());
        $this->assertFalse(DeviceTypeEnum::ADMIN->isMobile());
    }

    public function test_device_type_is_app(): void
    {
        $this->assertTrue(DeviceTypeEnum::APP_IOS->isApp());
        $this->assertTrue(DeviceTypeEnum::APP_ANDROID->isApp());
        $this->assertFalse(DeviceTypeEnum::PC->isApp());
        $this->assertFalse(DeviceTypeEnum::MOBILE->isApp());
    }

    // ========================================
    // OrderDateTypeEnum 테스트
    // ========================================

    public function test_order_date_type_has_correct_values(): void
    {
        $values = OrderDateTypeEnum::values();

        $this->assertIsArray($values);
        $this->assertContains('ordered_at', $values);
        $this->assertContains('paid_at', $values);
    }

    // ========================================
    // Enum label() 다국어 번역 완전성 테스트
    // ========================================

    /**
     * PaymentMethodEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_payment_method_label_returns_translated_string_for_all_cases(): void
    {
        foreach (PaymentMethodEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.payment_method.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "PaymentMethodEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "PaymentMethodEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * PaymentStatusEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_payment_status_label_returns_translated_string_for_all_cases(): void
    {
        foreach (PaymentStatusEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.payment_status.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "PaymentStatusEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "PaymentStatusEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * TaxInvoiceStatusEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_tax_invoice_status_label_returns_translated_string_for_all_cases(): void
    {
        foreach (TaxInvoiceStatusEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.tax_invoice_status.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "TaxInvoiceStatusEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "TaxInvoiceStatusEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * OrderStatusEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_order_status_label_returns_translated_string_for_all_cases(): void
    {
        foreach (OrderStatusEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.order_status.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "OrderStatusEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "OrderStatusEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * ShippingStatusEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_shipping_status_label_returns_translated_string_for_all_cases(): void
    {
        foreach (ShippingStatusEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.shipping_status.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "ShippingStatusEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "ShippingStatusEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * OrderOption의 option_status가 OrderStatusEnum을 사용하는지 검증합니다.
     */
    public function test_order_option_uses_order_status_enum(): void
    {
        // OrderStatusEnum에 주문옵션에서 사용하는 상태값이 모두 포함되어 있는지 확인
        $values = OrderStatusEnum::values();
        $this->assertContains('pending_order', $values);
        $this->assertContains('payment_complete', $values);
        $this->assertContains('preparing', $values);
        $this->assertContains('shipping', $values);
        $this->assertContains('delivered', $values);
        $this->assertContains('confirmed', $values);
        $this->assertContains('cancelled', $values);
    }

    /**
     * OrderOptionSourceTypeEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_order_option_source_type_label_returns_translated_string_for_all_cases(): void
    {
        foreach (OrderOptionSourceTypeEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.order_option_source_type.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "OrderOptionSourceTypeEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "OrderOptionSourceTypeEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * DeviceTypeEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_device_type_label_returns_translated_string_for_all_cases(): void
    {
        foreach (DeviceTypeEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.device_type.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "DeviceTypeEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "DeviceTypeEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }

    /**
     * OrderDateTypeEnum의 모든 case에 대해 label()이 번역된 문자열을 반환하는지 검증합니다.
     */
    public function test_order_date_type_label_returns_translated_string_for_all_cases(): void
    {
        foreach (OrderDateTypeEnum::cases() as $case) {
            $label = $case->label();
            $rawKey = 'sirsoft-ecommerce::enums.order_date_type.'.$case->value;

            $this->assertNotEquals(
                $rawKey,
                $label,
                "OrderDateTypeEnum::{$case->name} ({$case->value})의 다국어 키가 누락되었습니다."
            );
            $this->assertNotEmpty($label, "OrderDateTypeEnum::{$case->name}의 label()이 빈 문자열입니다.");
        }
    }
}
