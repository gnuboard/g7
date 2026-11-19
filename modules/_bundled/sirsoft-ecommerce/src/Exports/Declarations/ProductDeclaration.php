<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 (Product) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class ProductDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return Product::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'id' => ['type' => 'integer', 'personal' => false],
            'name' => ['type' => 'translatable', 'personal' => false],
            'product_code' => ['type' => 'string', 'personal' => false],
            'sales_product_code' => ['type' => 'string', 'personal' => false],
            'sku' => ['type' => 'string', 'personal' => false],
            'barcode' => ['type' => 'string', 'personal' => false],
            'hs_code' => ['type' => 'string', 'personal' => false],
            'list_price' => ['type' => 'money', 'personal' => false],
            'selling_price' => ['type' => 'money', 'personal' => false],
            'currency_code' => ['type' => 'string', 'personal' => false],
            'stock_quantity' => ['type' => 'integer', 'personal' => false, 'summable' => true],
            'safe_stock_quantity' => ['type' => 'integer', 'personal' => false],
            'sales_status' => ['type' => 'enum', 'personal' => false],
            'display_status' => ['type' => 'enum', 'personal' => false],
            'tax_status' => ['type' => 'enum', 'personal' => false],
            'tax_rate' => ['type' => 'decimal', 'personal' => false],
            'min_purchase_qty' => ['type' => 'integer', 'personal' => false],
            'max_purchase_qty' => ['type' => 'integer', 'personal' => false],
            'has_options' => ['type' => 'boolean', 'personal' => false],
            'created_at' => ['type' => 'datetime', 'personal' => false],
            'updated_at' => ['type' => 'datetime', 'personal' => false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'brand_id' => ExportExclusion::FOREIGN_KEY,
            'shipping_policy_id' => ExportExclusion::FOREIGN_KEY,
            'common_info_id' => ExportExclusion::FOREIGN_KEY,
            'purchase_restriction' => ExportExclusion::INTERNAL,
            'allowed_roles' => ExportExclusion::INTERNAL,
            'description' => ExportExclusion::LONG_CONTENT,
            'description_mode' => ExportExclusion::INTERNAL,
            'content_thumbnail_url' => ExportExclusion::INTERNAL,
            'meta_title' => ExportExclusion::SEO,
            'seo_sync_title' => ExportExclusion::SEO,
            'meta_description' => ExportExclusion::SEO,
            'seo_sync_description' => ExportExclusion::SEO,
            'meta_keywords' => ExportExclusion::SEO,
            'option_groups' => ExportExclusion::SNAPSHOT,
            'created_by' => ExportExclusion::FOREIGN_KEY,
            'updated_by' => ExportExclusion::FOREIGN_KEY,
            'deleted_at' => ExportExclusion::SOFT_DELETE,
        ];
    }
}
