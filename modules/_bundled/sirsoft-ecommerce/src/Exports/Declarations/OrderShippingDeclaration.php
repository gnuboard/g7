<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;

/**
 * 주문 배송 (OrderShipping) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class OrderShippingDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return OrderShipping::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'shipping_status' => ['type' => 'enum', 'personal' => false, 'enum' => ShippingStatusEnum::class],
            'shipping_type' => ['type' => 'string', 'personal' => false],
            'base_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'extra_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'total_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'shipping_discount_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'is_remote_area' => ['type' => 'boolean', 'personal' => false],
            'tracking_number' => ['type' => 'string', 'personal' => false],
            'return_shipping_amount' => ['type' => 'money', 'personal' => false, 'summable' => true],
            'package_number' => ['type' => 'string', 'personal' => false],
            'visit_date' => ['type' => 'date', 'personal' => false],
            'visit_time_slot' => ['type' => 'string', 'personal' => false],
            'actual_weight' => ['type' => 'decimal', 'personal' => false],
            'shipped_at' => ['type' => 'datetime', 'personal' => false],
            'estimated_arrival_at' => ['type' => 'datetime', 'personal' => false],
            'delivered_at' => ['type' => 'datetime', 'personal' => false],
            'confirmed_at' => ['type' => 'datetime', 'personal' => false],
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
            'order_option_id' => ExportExclusion::FOREIGN_KEY,
            'shipping_policy_id' => ExportExclusion::FOREIGN_KEY,
            'carrier_id' => ExportExclusion::FOREIGN_KEY,
            'delivery_policy_snapshot' => ExportExclusion::SNAPSHOT,
            'currency_snapshot' => ExportExclusion::SNAPSHOT,
            'mc_base_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_extra_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_total_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_shipping_discount_amount' => ExportExclusion::MULTI_CURRENCY,
            'mc_return_shipping_amount' => ExportExclusion::MULTI_CURRENCY,
            'created_at' => ExportExclusion::RECORD_META,
            'updated_at' => ExportExclusion::RECORD_META,
        ];
    }
}
