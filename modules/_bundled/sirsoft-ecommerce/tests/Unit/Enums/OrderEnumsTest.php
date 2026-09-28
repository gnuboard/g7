<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
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
        // 진행 9종 + 취소 1종 + 클레임 코스 4종(교환/반품 진행·완료)
        $this->assertCount(14, $values);
        $this->assertContains('pending_order', $values);
        $this->assertContains('cancelled', $values);
        $this->assertContains('exchange_in_progress', $values);
        $this->assertContains('return_in_progress', $values);
        $this->assertContains('exchanged', $values);
        $this->assertContains('returned', $values);
        $this->assertNotContains('partial_cancelled', $values, '부분취소는 별도 주문 상태가 아니다');
    }

    public function test_order_status_to_select_options_returns_array(): void
    {
        $options = OrderStatusEnum::toSelectOptions();

        $this->assertIsArray($options);
        $this->assertCount(14, $options);

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

    public function test_order_status_sync_excluded_statuses_are_separate_lifecycles(): void
    {
        $excluded = OrderStatusEnum::syncExcludedStatuses();

        // 주문→옵션 동기화에서 제외할 별도 라이프사이클 SSoT.
        // 부분취소는 별도 상태가 아니므로(잔여 옵션 기준 파생) 취소는 CANCELLED 뿐이고,
        // 여기에 클레임(교환/반품) 코스 상태 4종이 함께 들어간다.
        $this->assertContains(OrderStatusEnum::CANCELLED, $excluded);
        foreach (OrderStatusEnum::claimCourseStatuses() as $claimStatus) {
            $this->assertContains($claimStatus, $excluded);
        }

        // 활성 진행 상태는 동기화 대상이므로 제외 목록에 없어야 한다.
        $this->assertNotContains(OrderStatusEnum::PENDING_ORDER, $excluded);
        $this->assertNotContains(OrderStatusEnum::PAYMENT_COMPLETE, $excluded);
        $this->assertNotContains(OrderStatusEnum::SHIPPING, $excluded);
    }

    public function test_order_status_sync_excluded_values_returns_string_values(): void
    {
        $values = OrderStatusEnum::syncExcludedValues();

        $this->assertEquals(
            ['cancelled', 'exchange_in_progress', 'return_in_progress', 'exchanged', 'returned'],
            $values
        );
    }

    public function test_claim_course_statuses_are_excluded_from_admin_assignable(): void
    {
        $assignable = OrderStatusEnum::adminAssignableValues();

        // 클레임 코스 상태는 교환·반품 서비스가 신청서와 짝을 맞춰 기록하는 값이라
        // 관리자 주문 화면의 상태 변경 선택지가 아니다.
        foreach (OrderStatusEnum::claimCourseValues() as $claimValue) {
            $this->assertNotContains($claimValue, $assignable);
        }

        // 취소도 제외한다 — 취소는 상태 변경이 아니라 장부 행위이고, 입구는 클레임 접수 하나다.
        // 일괄 상태변경으로 찍으면 재고만 복원되고 돈·결제행은 그대로인 취소가 남는다.
        $this->assertNotContains('cancelled', $assignable);

        // 그 외 상태는 전부 남아 있어야 한다 (기존 계약 보존).
        $this->assertCount(9, $assignable);
        $this->assertContains('pending_order', $assignable);
        $this->assertContains('payment_complete', $assignable);
    }

    public function test_claim_course_statuses_are_not_in_progress_or_sales_order(): void
    {
        // 진행 순서·매출 반영 집합은 불변이어야 한다 — 기존 계산·통계 계약 보호.
        foreach (OrderStatusEnum::claimCourseStatuses() as $claimStatus) {
            $this->assertNotContains($claimStatus, OrderStatusEnum::progressOrder());
            $this->assertNotContains($claimStatus, OrderStatusEnum::salesEligibleStatuses());
        }

        $this->assertCount(9, OrderStatusEnum::progressOrder());
        $this->assertCount(7, OrderStatusEnum::salesEligibleStatuses());
    }

    public function test_claim_in_progress_statuses_can_return_to_sales_states(): void
    {
        // 교환·반품이 반려·철회되면 그 상품옵션은 신청 직전 상태로 돌아가야 한다.
        // 게이트가 막으면 클레임 서비스가 게이트를 우회해야 하므로 구간을 연다 —
        // 정확한 복귀 대상은 신청 상품의 original_option_status 가 좁힌다.
        foreach (OrderStatusEnum::revertibleClaimCourseStatuses() as $courseStatus) {
            $this->assertSame(
                OrderStatusEnum::salesEligibleStatuses(),
                $courseStatus->allowedTransitions()
            );
            $this->assertTrue($courseStatus->canTransitionTo(OrderStatusEnum::DELIVERED));
            $this->assertTrue($courseStatus->canTransitionTo(OrderStatusEnum::SHIPPING));

            // 결제 전 상태로의 복귀는 취소 reactivation 과 동일하게 막는다.
            $this->assertFalse($courseStatus->canTransitionTo(OrderStatusEnum::PENDING_PAYMENT));
        }
    }

    public function test_claim_terminal_statuses_stay_terminal(): void
    {
        // 완료 시점에 환불 집행·재고 복구가 끝나 있어 상태만 되돌리면 기록과 어긋난다 (확정).
        foreach ([OrderStatusEnum::EXCHANGED, OrderStatusEnum::RETURNED] as $terminal) {
            $this->assertSame([], $terminal->allowedTransitions());
            $this->assertFalse($terminal->canTransitionTo(OrderStatusEnum::DELIVERED));
            $this->assertNotContains($terminal, OrderStatusEnum::revertibleClaimCourseStatuses());
        }
    }

    public function test_revertible_claim_course_is_the_in_progress_pair(): void
    {
        $this->assertSame(
            [OrderStatusEnum::EXCHANGE_IN_PROGRESS, OrderStatusEnum::RETURN_IN_PROGRESS],
            OrderStatusEnum::revertibleClaimCourseStatuses()
        );
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

    /**
     * 통계 카운터→필터 그룹 매핑이 상품준비중을 두 상태로 확장하는지 검증합니다.
     */
    public function test_statistics_filter_group_expands_preparing(): void
    {
        $groups = OrderStatusEnum::statisticsFilterGroups();

        $this->assertArrayHasKey(OrderStatusEnum::PREPARING->value, $groups);
        $this->assertEqualsCanonicalizing(
            [OrderStatusEnum::PREPARING->value, OrderStatusEnum::SHIPPING_READY->value],
            $groups[OrderStatusEnum::PREPARING->value]
        );
    }

    /**
     * expandStatisticsFilter 는 그룹 키는 확장하고 일반 상태 값은 그대로 반환합니다(멱등).
     */
    public function test_expand_statistics_filter_idempotent_for_non_group(): void
    {
        $this->assertEqualsCanonicalizing(
            [OrderStatusEnum::PREPARING->value, OrderStatusEnum::SHIPPING_READY->value],
            OrderStatusEnum::expandStatisticsFilter(OrderStatusEnum::PREPARING->value)
        );

        $this->assertSame(
            [OrderStatusEnum::SHIPPING->value],
            OrderStatusEnum::expandStatisticsFilter(OrderStatusEnum::SHIPPING->value)
        );
    }

    // ========================================
    // 상태 아이콘 · 진행단계 순서 (스텝퍼 · 상태 뱃지 공용)
    // ========================================

    /**
     * 모든 주문 상태가 라이브러리 중립 의미 토큰(`status-*`)을 가진다. 백엔드가 특정 아이콘
     * 라이브러리의 글리프 이름을 박으면 그 라이브러리에 묶인다 — 토큰을 글리프로 바꾸는 표는
     * 템플릿의 Icon 컴포넌트가 가진다. 어휘 밖 토큰은 화면에서 빈 네모로 그려진다.
     *
     * @scenario surface=admin_detail, order_stage=in_progress, viewport=desktop
     *
     * @effects order_progress_steps_carry_icon_and_variant
     */
    public function test_every_order_status_has_a_library_neutral_icon_token(): void
    {
        $allowed = [
            'status-request', 'status-payment-pending', 'status-paid', 'status-approved', 'status-on-hold',
            'status-rejected', 'status-pickup-ready', 'status-pickup', 'status-received', 'status-dispatch-ready',
            'status-dispatch', 'status-delivered', 'status-completed', 'status-withdrawn', 'status-processing',
            'status-preparing', 'status-cancelled', 'status-exchange', 'status-return', 'status-exchanged', 'status-returned',
        ];

        foreach (OrderStatusEnum::cases() as $case) {
            $this->assertContains($case->icon(), $allowed, "OrderStatusEnum::{$case->name} 의 아이콘 토큰이 어휘 밖입니다.");
            $this->assertStringStartsWith('status-', $case->icon());
            $this->assertStringStartsNotWith('fa-', $case->icon(), '백엔드는 아이콘 라이브러리 글리프 이름을 알지 않습니다.');
        }
    }

    /**
     * 클레임과 같은 단계는 같은 그림이어야 구매자가 화면마다 단계를 다시 익히지 않는다.
     *
     * @scenario surface=mypage_detail, order_stage=in_progress, viewport=desktop
     *
     * @effects order_status_badge_carries_icon
     */
    public function test_shared_stages_use_the_same_icon_as_claims(): void
    {
        $this->assertSame(ExchangeStatusEnum::PAYMENT_PENDING->icon(), OrderStatusEnum::PENDING_PAYMENT->icon());
        $this->assertSame(ExchangeStatusEnum::PAID->icon(), OrderStatusEnum::PAYMENT_COMPLETE->icon());
        $this->assertSame(ExchangeStatusEnum::ON_HOLD->icon(), OrderStatusEnum::SHIPPING_HOLD->icon());
        $this->assertSame(ExchangeStatusEnum::REDELIVERY_READY->icon(), OrderStatusEnum::SHIPPING_READY->icon());
        $this->assertSame(ExchangeStatusEnum::REDELIVERING->icon(), OrderStatusEnum::SHIPPING->icon());
        $this->assertSame(ExchangeStatusEnum::REDELIVERED->icon(), OrderStatusEnum::DELIVERED->icon());
        $this->assertSame(ExchangeStatusEnum::COMPLETED->icon(), OrderStatusEnum::CONFIRMED->icon());
    }

    /**
     * 진행단계는 7단계이고, 이탈 상태 6종은 순서 밖이다. 주문대기는 순서 밖이지만 이탈이 아니다.
     *
     * @scenario surface=admin_detail, order_stage=off_path, viewport=desktop
     *
     * @effects order_progress_steps_seven_stages, order_progress_off_path_appends_last_cell
     */
    public function test_progress_sequence_is_seven_stages_and_off_path_is_outside(): void
    {
        $sequence = OrderStatusEnum::progressSequence();

        $this->assertSame(
            ['pending_payment', 'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'delivered', 'confirmed'],
            array_map(fn ($case) => $case->value, $sequence),
        );

        foreach ($sequence as $case) {
            $this->assertFalse($case->isOffProgressPath(), "{$case->name} 은 순서 안의 단계입니다.");
        }

        foreach ([OrderStatusEnum::SHIPPING_HOLD, OrderStatusEnum::CANCELLED, OrderStatusEnum::EXCHANGE_IN_PROGRESS, OrderStatusEnum::RETURN_IN_PROGRESS, OrderStatusEnum::EXCHANGED, OrderStatusEnum::RETURNED] as $case) {
            $this->assertTrue($case->isOffProgressPath(), "{$case->name} 은 이탈 상태입니다.");
            $this->assertNotContains($case, $sequence);
        }

        $this->assertFalse(OrderStatusEnum::PENDING_ORDER->isOffProgressPath());
        $this->assertNotContains(OrderStatusEnum::PENDING_ORDER, $sequence);
    }
}
