<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 쇼핑 진열·판매 집계가 쓰는 색인을 추가합니다.
 *
 * 공개 상품 목록은 전시 조건으로 좁힌 뒤 등록일 또는 판매가로 정렬하는데, 정렬까지 덮는
 * 색인이 없어 조건을 만족하는 행을 고른 뒤 정렬을 다시 해야 했습니다. 판매량 집계는
 * 주문 옵션을 상품별로 묶어 수량을 더하는데, 색인이 묶는 것까지만 도와 수량과 기간은
 * 테이블 본문에서 읽어야 했습니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 색인을 적용합니다.
 *
 * 데이터가 많은 쇼핑몰에서는 색인마다 수 분 걸릴 수 있고 그동안 해당 테이블 쓰기가
 * 대기합니다.
 *
 * idempotent: 이미 적용된 대상은 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddStorefrontIndexes implements DataMigration
{
    /**
     * 대상 [색인명 => [테이블, 컬럼]]
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    private const TARGETS = [
        'idx_products_display_created_id' => ['ecommerce_products', ['display_status', 'created_at', 'id']],
        'idx_products_display_price_id' => ['ecommerce_products', ['display_status', 'selling_price', 'id']],
        'idx_order_options_product_created_qty' => ['ecommerce_order_options', ['product_id', 'created_at', 'quantity']],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddStorefrontIndexes';
    }

    /**
     * 진열·집계 색인을 적용합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        foreach (self::TARGETS as $indexName => [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[ecommerce:1.0.5] 테이블 부재 — 색인 적용 스킵: {$table}");

                continue;
            }

            if (in_array($indexName, array_column(Schema::getIndexes($table), 'name'), true)) {
                $context->logger->info("[ecommerce:1.0.5] 이미 적용된 색인 — 스킵: {$indexName}");

                continue;
            }

            $context->logger->info("[ecommerce:1.0.5] 진열·집계 색인 적용 시작: {$table} (데이터가 많으면 수 분 걸릴 수 있고 그동안 해당 테이블 쓰기가 대기합니다)");

            Schema::table($table, function ($blueprint) use ($columns, $indexName) {
                $blueprint->index($columns, $indexName);
            });

            $context->logger->info("[ecommerce:1.0.5] 색인 적용 완료: {$indexName}");
        }
    }
}
