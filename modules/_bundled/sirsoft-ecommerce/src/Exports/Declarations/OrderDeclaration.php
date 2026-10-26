<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 (Order) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class OrderDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return Order::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'order_number' => ['type' => 'string', 'personal' => false],
            'order_status' => ['type' => 'enum', 'personal' => false],
            'order_device' => ['type' => 'enum', 'personal' => false, 'enum' => DeviceTypeEnum::class],
            'is_first_order' => ['type' => 'boolean', 'personal' => false],
            'ip_address' => ['type' => 'string', 'personal' => true],
            'currency' => ['type' => 'string', 'personal' => false],
            'subtotal_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_coupon_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_product_coupon_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_order_coupon_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_code_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'base_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'extra_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'shipping_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_tax_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_vat_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_tax_free_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_points_used_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_deposit_used_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_paid_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_due_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_cash_equivalent_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_cancelled_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_refunded_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_refunded_points_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_earned_points_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'cancellation_count' => ['type' => 'integer', 'personal' => false],
            'item_count' => ['type' => 'integer', 'personal' => false, 'summable' => true],
            'total_weight' => ['type' => 'decimal', 'personal' => false],
            'total_volume' => ['type' => 'decimal', 'personal' => false],
            'ordered_at' => ['type' => 'datetime', 'personal' => false],
            'paid_at' => ['type' => 'datetime', 'personal' => false],
            'payment_due_at' => ['type' => 'datetime', 'personal' => false],
            'confirmed_at' => ['type' => 'datetime', 'personal' => false],
            'cancelled_at' => ['type' => 'datetime', 'personal' => false],
            'admin_memo' => ['type' => 'string', 'personal' => true],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'id' => ExportExclusion::PRIMARY_KEY,
            'user_id' => ExportExclusion::FOREIGN_KEY,
            'guest_lookup_password_hash' => ExportExclusion::SECRET,
            'is_mileage_deducted' => ExportExclusion::INTERNAL,
            'currency_snapshot' => ExportExclusion::SNAPSHOT,
            'created_at' => ExportExclusion::RECORD_META,
            'updated_at' => ExportExclusion::RECORD_META,
            'deleted_at' => ExportExclusion::SOFT_DELETE,
            'promotions_applied_snapshot' => ExportExclusion::SNAPSHOT,
            'shipping_policy_applied_snapshot' => ExportExclusion::SNAPSHOT,
            'mileage_policy_snapshot' => ExportExclusion::SNAPSHOT,
            'promotions_available_snapshot' => ExportExclusion::SNAPSHOT,
            'order_meta' => ExportExclusion::INTERNAL,
            'mc_subtotal_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_product_coupon_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_order_coupon_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_coupon_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_code_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_base_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_extra_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_shipping_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_tax_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_tax_free_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_points_used_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_deposit_used_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_paid_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_cash_equivalent_amount' => ExportExclusion::MULTI_CURRENCY,
        ];
    }
}
