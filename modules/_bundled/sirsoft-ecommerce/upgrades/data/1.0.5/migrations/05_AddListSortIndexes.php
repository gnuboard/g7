<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 이커머스 목록의 정렬 색인을 추가·교체합니다.
 *
 * 주문·상품·상품문의 목록은 정렬 순서를 덮는 색인이 없어 뒤쪽 페이지가 테이블 전체를 훑었고,
 * 상품후기·쿠폰 발급이력은 색인이 정렬 컬럼에서 끝나 같은 시각 구간에 추가 정렬 작업이
 * 남았습니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 색인을 적용합니다.
 *
 * 데이터가 많은 쇼핑몰에서는 색인마다 수 분 걸릴 수 있고 그동안 해당 테이블 쓰기가
 * 대기합니다. 새 색인을 먼저 만들고 기존 색인을 나중에 지우므로, 중간에 중단되어도 조회가
 * 색인 없이 남는 구간은 없습니다.
 *
 * idempotent: 이미 적용된 대상은 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddListSortIndexes implements DataMigration
{
    /**
     * 대상 [테이블 => [신규 색인명, 컬럼, 교체 대상 기존 색인명(없으면 null)]]
     *
     * @var array<string, array{0: string, 1: array<int, string>, 2: string|null}>
     */
    private const TARGETS = [
        'ecommerce_orders' => ['idx_orders_deleted_ordered_id', ['deleted_at', 'ordered_at', 'id'], null],
        'ecommerce_products' => ['idx_products_deleted_created_id', ['deleted_at', 'created_at', 'id'], null],
        'ecommerce_product_inquiries' => ['idx_inquiries_created_id', ['created_at', 'id'], null],
        'ecommerce_product_reviews' => ['idx_reviews_deleted_created_id', ['deleted_at', 'created_at', 'id'], 'idx_reviews_deleted_created'],
        'ecommerce_promotion_coupon_issues' => ['idx_coupon_issues_issued_id', ['issued_at', 'id'], 'idx_issued_at'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddListSortIndexes';
    }

    /**
     * 목록 정렬 색인을 적용합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        foreach (self::TARGETS as $table => [$newIndex, $columns, $oldIndex]) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[ecommerce:1.0.5] 테이블 부재 — 색인 적용 스킵: {$table}");

                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            if (in_array($newIndex, $existing, true)) {
                $context->logger->info("[ecommerce:1.0.5] 이미 적용된 색인 — 스킵: {$newIndex}");
            } else {
                $context->logger->info("[ecommerce:1.0.5] 목록 정렬 색인 적용 시작: {$table} (데이터가 많으면 수 분 걸릴 수 있고 그동안 해당 테이블 쓰기가 대기합니다)");

                Schema::table($table, function ($blueprint) use ($columns, $newIndex) {
                    $blueprint->index($columns, $newIndex);
                });

                $context->logger->info("[ecommerce:1.0.5] 색인 적용 완료: {$newIndex}");
            }

            // 기존 색인은 새 색인의 좌측 프리픽스라 남겨 두면 쓰기 비용만 늘어난다.
            // 반드시 새 색인 생성 이후에 지운다.
            if ($oldIndex !== null && in_array($oldIndex, $existing, true)) {
                Schema::table($table, function ($blueprint) use ($oldIndex) {
                    $blueprint->dropIndex($oldIndex);
                });

                $context->logger->info("[ecommerce:1.0.5] 상위집합에 포함된 기존 색인 제거: {$oldIndex}");
            }
        }
    }
}
