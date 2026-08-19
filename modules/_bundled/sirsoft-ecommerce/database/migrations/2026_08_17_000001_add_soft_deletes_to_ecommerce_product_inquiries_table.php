<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 문의 피벗 테이블에 소프트 삭제 컬럼을 추가합니다.
 *
 * 게시판에서 질문 글을 삭제했다가 복원하는 경로에서 피벗도 함께 복원되어야
 * 하는데, 종전에는 피벗이 하드 삭제라 복원이 불가능했습니다(#107 후속).
 * 상품 자체가 영구 삭제되는 경로만 피벗도 영구 삭제합니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_product_inquiries')
            || Schema::hasColumn('ecommerce_product_inquiries', 'deleted_at')) {
            return;
        }

        Schema::table('ecommerce_product_inquiries', function (Blueprint $table) {
            $table->softDeletes()->comment('소프트 삭제 일시');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_product_inquiries')
            || ! Schema::hasColumn('ecommerce_product_inquiries', 'deleted_at')) {
            return;
        }

        Schema::table('ecommerce_product_inquiries', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
