<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 상품 옵션 (ProductOption) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class ProductOptionDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return ProductOption::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'id' => ['type' => 'integer', 'personal' => false],
            'option_code' => ['type' => 'string', 'personal' => false],
            'option_name' => ['type' => 'translatable', 'personal' => false],
            'sku' => ['type' => 'string', 'personal' => false],
            'price_adjustment' => ['type' => 'money', 'personal' => false],
            'list_price' => ['type' => 'money', 'personal' => false],
            'selling_price' => ['type' => 'money', 'personal' => false],
            'currency_code' => ['type' => 'string', 'personal' => false],
            'stock_quantity' => ['type' => 'integer', 'personal' => false, 'summable' => true],
            'safe_stock_quantity' => ['type' => 'integer', 'personal' => false],
            'weight' => ['type' => 'decimal', 'personal' => false],
            'volume' => ['type' => 'decimal', 'personal' => false],
            'mileage_value' => ['type' => 'decimal', 'personal' => false],
            'mileage_type' => ['type' => 'string', 'personal' => false],
            'is_default' => ['type' => 'boolean', 'personal' => false],
            'is_active' => ['type' => 'boolean', 'personal' => false],
            // 옵션별 과세 종류 — 비어 있으면 상품 값을 따른다(Enum 은 모델의 활동 로그 선언에서 받는다)
            'tax_status' => ['type' => 'enum', 'personal' => false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'product_id' => ExportExclusion::FOREIGN_KEY,
            'option_values' => ExportExclusion::SNAPSHOT,
            'sort_order' => ExportExclusion::INTERNAL,
            'created_at' => ExportExclusion::RECORD_META,
            'updated_at' => ExportExclusion::RECORD_META,
        ];
    }
}
