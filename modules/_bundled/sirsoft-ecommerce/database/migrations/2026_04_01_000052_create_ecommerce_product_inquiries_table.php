<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_product_inquiries', function (Blueprint $table) {
            $table->id()->comment('상품 문의 피벗 ID');

            // 상품 연결
            $table->unsignedBigInteger('product_id')->comment('상품 ID (ecommerce_products.id 참조)');

            // 다형성 연관 (게시판 Post 등)
            $table->string('inquirable_type', 100)->comment('문의 원본 모델 클래스명 (예: Modules\\Sirsoft\\Board\\Models\\Post)');
            $table->unsignedBigInteger('inquirable_id')->comment('문의 원본 레코드 ID (board_posts.id 등)');

            // 작성자 (비회원: null)
            $table->unsignedBigInteger('user_id')->nullable()->comment('작성자 회원 ID (비회원: null)');

            // 답변 상태
            $table->boolean('is_answered')->default(false)->comment('답변 완료 여부 (false: 대기, true: 완료)');
            $table->timestamp('answered_at')->nullable()->comment('답변 완료 일시');

            // 스냅샷
            $table->text('product_name_snapshot')->nullable()->comment('작성 시점 상품명 스냅샷 ({"ko": "상품명", "en": "Product Name"})');

            $table->timestamps();

            // 인덱스
            $table->index(['inquirable_type', 'inquirable_id'], 'idx_inquirable');
            $table->index('product_id');
            $table->index('user_id');

            // 외래키
            $table->foreign('product_id')
                ->references('id')
                ->on('ecommerce_products')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // 테이블 COMMENT 추가 (MySQL 전용)
        if (DB::getDriverName() == 'mysql') {
            Schema::table('ecommerce_product_inquiries', function (Blueprint $table) {
                $table->comment('상품 1:1 문의 피벗 테이블 (게시판 모듈 연동)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_product_inquiries')) {
            Schema::table('ecommerce_product_inquiries', function (Blueprint $table) {
                if (Schema::hasColumn('ecommerce_product_inquiries', 'product_id')) {
                    $table->dropForeign(['product_id']);
                }
                if (Schema::hasColumn('ecommerce_product_inquiries', 'user_id')) {
                    $table->dropForeign(['user_id']);
                }
            });
            Schema::dropIfExists('ecommerce_product_inquiries');
        }
    }
};
