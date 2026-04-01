<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 이미지 및 리뷰 이미지 테이블에서 불필요한 url 컬럼 삭제
 *
 * 모든 이미지 서빙은 hash 기반 download_url (API 엔드포인트)을 사용하므로
 * DB에 저장되던 url 컬럼은 더 이상 사용되지 않습니다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     *
     * @return void
     */
    public function up(): void
    {
        // 상품 이미지 테이블에서 url 컬럼 삭제
        if (Schema::hasColumn('ecommerce_product_images', 'url')) {
            Schema::table('ecommerce_product_images', function (Blueprint $table) {
                $table->dropColumn('url');
            });
        }

        // 리뷰 이미지 테이블에서 url 컬럼 삭제
        if (Schema::hasColumn('ecommerce_product_review_images', 'url')) {
            Schema::table('ecommerce_product_review_images', function (Blueprint $table) {
                $table->dropColumn('url');
            });
        }
    }

    /**
     * 마이그레이션 롤백
     *
     * @return void
     */
    public function down(): void
    {
        // 상품 이미지 테이블에 url 컬럼 복원
        if (! Schema::hasColumn('ecommerce_product_images', 'url')) {
            Schema::table('ecommerce_product_images', function (Blueprint $table) {
                $table->string('url')->nullable()->after('path')->comment('이미지 URL (미사용, download_url로 대체)');
            });
        }

        // 리뷰 이미지 테이블에 url 컬럼 복원
        if (! Schema::hasColumn('ecommerce_product_review_images', 'url')) {
            Schema::table('ecommerce_product_review_images', function (Blueprint $table) {
                $table->string('url')->nullable()->after('path')->comment('이미지 URL (미사용, download_url로 대체)');
            });
        }
    }
};
