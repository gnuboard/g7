<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 주문 상품(옵션) (OrderOption) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class OrderOptionDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return OrderOption::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'option_status' => ['type' => 'enum', 'personal' => false],
            'sku' => ['type' => 'string', 'personal' => false],
            'product_name' => ['type' => 'translatable', 'personal' => false],
            'product_option_name' => ['type' => 'translatable', 'personal' => false],
            'option_name' => ['type' => 'translatable', 'personal' => false],
            'option_value' => ['type' => 'translatable', 'personal' => false],
            'quantity' => ['type' => 'integer', 'personal' => false, 'summable' => true],
            'cancelled_quantity' => ['type' => 'integer', 'personal' => false, 'summable' => true],
            'cancel_reason' => ['type' => 'string', 'personal' => false],
            'unit_price' => ['type' => 'money', 'personal' => false],
            'additional_options_total' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_price' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'coupon_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'product_coupon_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'order_coupon_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'code_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_points_used_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_deposit_used_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_paid_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_tax_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_tax_free_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            // 주문 시점에 기록한 과세 종류 — 이 칸이 없던 시절의 행은 비어 있다
            'tax_status' => ['type' => 'enum', 'personal' => false, 'enum' => ProductTaxStatus::class],
            'subtotal_earned_points_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'subtotal_cash_equivalent_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'unit_weight' => ['type' => 'decimal', 'personal' => false],
            'unit_volume' => ['type' => 'decimal', 'personal' => false],
            'subtotal_weight' => ['type' => 'decimal', 'personal' => false],
            'subtotal_volume' => ['type' => 'decimal', 'personal' => false],
            'confirmed_at' => ['type' => 'datetime', 'personal' => false],
            'delivered_at' => ['type' => 'datetime', 'personal' => false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'id' => ExportExclusion::PRIMARY_KEY,
            'order_id' => ExportExclusion::FOREIGN_KEY,
            'parent_option_id' => ExportExclusion::FOREIGN_KEY,
            'product_id' => ExportExclusion::FOREIGN_KEY,
            'product_option_id' => ExportExclusion::FOREIGN_KEY,
            'is_stock_deducted' => ExportExclusion::INTERNAL,
            'source_type' => ExportExclusion::INTERNAL,
            'source_option_id' => ExportExclusion::FOREIGN_KEY,
            'product_snapshot' => ExportExclusion::SNAPSHOT,
            'option_snapshot' => ExportExclusion::SNAPSHOT,
            'additional_options_snapshot' => ExportExclusion::SNAPSHOT,
            'promotions_applied_snapshot' => ExportExclusion::SNAPSHOT,
            'external_option_id' => ExportExclusion::INTERNAL,
            'external_meta' => ExportExclusion::INTERNAL,
            'created_at' => ExportExclusion::RECORD_META,
            'updated_at' => ExportExclusion::RECORD_META,
            'mc_unit_price' => ExportExclusion::MULTI_CURRENCY,
            'mc_additional_options_total' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_price' => ExportExclusion::MULTI_CURRENCY,
            'mc_product_coupon_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_order_coupon_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_coupon_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_code_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_points_used_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_earned_points_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_deposit_used_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_tax_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_tax_free_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_final_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_subtotal_cash_equivalent_amount' => ExportExclusion::MULTI_CURRENCY,
        ];
    }
}
