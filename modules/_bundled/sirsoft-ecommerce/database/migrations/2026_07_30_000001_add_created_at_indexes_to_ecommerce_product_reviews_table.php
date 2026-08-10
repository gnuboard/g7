<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_product_reviews 테이블 정렬 인덱스 추가.
 *
 * 리뷰 목록은 상품 상세와 관리자 화면 모두 작성일 내림차순이 기본 정렬인데, created_at 이
 * 후행하는 복합 인덱스가 없어 정렬마다 filesort 가 발생했다.
 *
 * - [product_id, status, deleted_at, created_at]: 상품 상세의 노출 리뷰 목록
 *   (기존 [product_id, status] 인덱스를 포함하므로 그 인덱스는 후속 정리 대상)
 * - [deleted_at, created_at]: 관리자 리뷰 목록
 *
 * 관리자 목록에 [status, created_at] 을 두지 않는 이유: status 는 **선택적 필터**다
 * (ProductReviewRepository 의 상태 필터는 값이 있을 때만 where 를 붙인다). 선행 컬럼이
 * 등치로 고정되지 않으면 후행 created_at 의 정렬 순서를 쓸 수 없어, 필터를 걸지 않은
 * 기본 진입에서 filesort 가 그대로 남는다. 반면 deleted_at 은 SoftDeletes 가 항상
 * `IS NULL` 로 붙이므로 선행 컬럼이 고정된다. 상태 필터를 건 조회도 이 인덱스로 정렬을
 * 충족하고 status 만 추가로 걸러내면 된다.
 *
 * 별점(rating) 정렬용 인덱스는 추가하지 않는다. 값이 1~5 뿐이라 선택도가 낮아 인덱스 효용이
 * 없고, 쓰기 비용만 늘어난다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_product_reviews')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_product_reviews'), 'name');

        Schema::table('ecommerce_product_reviews', function (Blueprint $table) use ($existingIndexes) {
            if (! in_array('idx_reviews_product_status_deleted_created', $existingIndexes)) {
                $table->index(
                    ['product_id', 'status', 'deleted_at', 'created_at'],
                    'idx_reviews_product_status_deleted_created'
                );
            }

            if (! in_array('idx_reviews_deleted_created', $existingIndexes)) {
                $table->index(['deleted_at', 'created_at'], 'idx_reviews_deleted_created');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_product_reviews')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('ecommerce_product_reviews'), 'name');

        Schema::table('ecommerce_product_reviews', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'idx_reviews_product_status_deleted_created',
                'idx_reviews_deleted_created',
            ];

            foreach ($indexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
