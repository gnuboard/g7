<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * v0.18.0 업그레이드 스텝
 *
 * 검색/필터/정렬 성능 향상을 위한 인덱스 추가 검증.
 * - ecommerce_products: 7개 인덱스
 * - ecommerce_orders: 4개 인덱스
 * - ecommerce_order_addresses: 2개 인덱스
 */
class Upgrade_0_18_0 implements UpgradeStepInterface
{
    /**
     * 검증 대상 인덱스 목록.
     *
     * @var array<string, string[]>
     */
    private const EXPECTED_INDEXES = [
        'ecommerce_products' => [
            'idx_ecom_products_selling_price',
            'idx_ecom_products_list_price',
            'idx_ecom_products_stock_qty',
            'idx_ecom_products_shipping_policy',
            'idx_ecom_products_barcode',
            'idx_ecom_products_tax_status',
            'idx_ecom_products_updated_at',
        ],
        'ecommerce_orders' => [
            'idx_ecom_orders_total_amount',
            'idx_ecom_orders_device',
            'idx_ecom_orders_confirmed_at',
            'idx_ecom_orders_user_ordered',
        ],
        'ecommerce_order_addresses' => [
            'idx_ecom_addr_orderer_phone',
            'idx_ecom_addr_recipient_phone',
        ],
    ];

    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $totalExpected = 0;
        $totalFound = 0;

        foreach (self::EXPECTED_INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[v0.18.0] {$table} 테이블이 존재하지 않습니다.");

                continue;
            }

            $existingIndexes = collect(Schema::getIndexes($table))->pluck('name')->toArray();

            foreach ($indexes as $indexName) {
                $totalExpected++;

                if (in_array($indexName, $existingIndexes)) {
                    $totalFound++;
                } else {
                    $context->logger->warning("[v0.18.0] {$table} 테이블에 {$indexName} 인덱스가 없습니다. 마이그레이션을 실행하세요.");
                }
            }
        }

        $context->logger->info("[v0.18.0] 이커머스 인덱스 검증 완료: {$totalFound}/{$totalExpected}개 확인됨");
    }
}
