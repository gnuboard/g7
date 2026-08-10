<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이커머스 목록의 정렬 색인을 추가·교체합니다.
 *
 * 각 목록은 소프트 삭제 조건으로 좁힌 뒤 작성일(또는 주문일·발급일) 내림차순으로 정렬하는데,
 * 그 순서를 덮는 색인이 없거나 색인이 정렬 컬럼에서 끝나 동률 구간에 filesort 가 남았다.
 * 지연 조인의 inner 가 키 컬럼만 읽어도 인덱스 순서로 끝나지 못하면 깊은 OFFSET 개선 폭이
 * 사라진다.
 *
 * 필요한 색인은 계측 프로파일 선언(filters / order / soft_delete)에서 도출한 것이며,
 * 이커머스 모듈의 `ListIndexCoverageTest` 가 같은 규칙으로 전 목록을 검사한다.
 *
 * 신설 — 정렬을 덮는 색인이 아예 없던 목록
 *  - ecommerce_orders                 (deleted_at, ordered_at, id)  주문 관리 목록
 *  - ecommerce_products               (deleted_at, created_at, id)  상품 관리 목록
 *  - ecommerce_product_inquiries      (created_at, id)              상품 문의 목록
 *
 * 교체 — 색인은 있으나 정렬 컬럼에서 끝나 동률 구간이 filesort 로 남던 목록
 *  - ecommerce_product_reviews        (deleted_at, created_at) → + id
 *  - ecommerce_promotion_coupon_issues (issued_at) → + id
 *
 * 교체 대상 색인은 새 색인의 좌측 프리픽스라 그대로 두면 쓰기 비용만 늘어난다. 새 색인을
 * 먼저 만들고 기존 색인을 나중에 지우므로, 중간에 중단되어도 조회가 색인 없이 남는 구간은 없다.
 */
return new class extends Migration
{
    /**
     * 대상 [테이블 => [신규 색인명, 컬럼, 교체 대상 기존 색인명(없으면 null), 되돌릴 컬럼]]
     *
     * @var array<string, array{0: string, 1: array<int, string>, 2: string|null, 3: array<int, string>}>
     */
    private const TARGETS = [
        'ecommerce_orders' => [
            'idx_orders_deleted_ordered_id', ['deleted_at', 'ordered_at', 'id'], null, [],
        ],
        'ecommerce_products' => [
            'idx_products_deleted_created_id', ['deleted_at', 'created_at', 'id'], null, [],
        ],
        'ecommerce_product_inquiries' => [
            'idx_inquiries_created_id', ['created_at', 'id'], null, [],
        ],
        'ecommerce_product_reviews' => [
            'idx_reviews_deleted_created_id', ['deleted_at', 'created_at', 'id'],
            'idx_reviews_deleted_created', ['deleted_at', 'created_at'],
        ],
        'ecommerce_promotion_coupon_issues' => [
            'idx_coupon_issues_issued_id', ['issued_at', 'id'], 'idx_issued_at', ['issued_at'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TARGETS as $table => [$newIndex, $columns, $oldIndex, $oldColumns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($existing, $newIndex, $columns, $oldIndex) {
                if (! in_array($newIndex, $existing, true)) {
                    $blueprint->index($columns, $newIndex);
                }

                if ($oldIndex !== null && in_array($oldIndex, $existing, true)) {
                    $blueprint->dropIndex($oldIndex);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TARGETS as $table => [$newIndex, $columns, $oldIndex, $oldColumns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($existing, $newIndex, $oldIndex, $oldColumns) {
                if ($oldIndex !== null && $oldColumns !== [] && ! in_array($oldIndex, $existing, true)) {
                    $blueprint->index($oldColumns, $oldIndex);
                }

                if (in_array($newIndex, $existing, true)) {
                    $blueprint->dropIndex($newIndex);
                }
            });
        }
    }
};
