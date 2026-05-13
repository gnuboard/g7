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
        Schema::create('ecommerce_product_reviews', function (Blueprint $table) {
            $table->id()->comment('리뷰 ID');
            $table->unsignedBigInteger('product_id')->comment('상품 ID');
            $table->unsignedBigInteger('order_option_id')->comment('주문 옵션 ID');
            $table->unsignedBigInteger('user_id')->comment('작성자 ID');

            // 리뷰 내용
            $table->tinyInteger('rating')->unsigned()->comment('별점 (1~5)');
            $table->text('content')->comment('리뷰 내용');
            $table->string('content_mode', 10)->default('text')->comment('콘텐츠 모드: text / html');

            // 주문 시점 옵션 스냅샷
            $table->mediumText('option_snapshot')->nullable()->comment('주문 시점 옵션 스냅샷 (옵션명 보존용)');

            // 상태
            $table->string('status', 20)->default('visible')->comment('리뷰 상태: visible / hidden');

            // 판매자 답변
            $table->text('reply_content')->nullable()->comment('판매자 답변 내용');
            $table->string('reply_content_mode', 10)->default('text')->comment('답변 콘텐츠 모드: text / html');
            $table->foreignId('reply_admin_id')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('답변 등록 관리자 ID');
            $table->timestamp('replied_at')->nullable()->comment('답변 등록일시');
            $table->timestamp('reply_updated_at')->nullable()->comment('답변 수정일시');

            $table->timestamps();
            $table->softDeletes()->comment('소프트 삭제 일시');

            // 인덱스
            $table->index('product_id');
            $table->index('user_id');
            $table->index('order_option_id');
            $table->index(['product_id', 'status']);
            $table->index(['user_id', 'product_id']);
            $table->index('deleted_at');
            $table->index(['replied_at', 'deleted_at'], 'idx_reviews_replied_at_deleted_at');

            // 외래키
            $table->foreign('product_id')
                ->references('id')
                ->on('ecommerce_products')
                ->onDelete('cascade');

            $table->foreign('order_option_id')
                ->references('id')
                ->on('ecommerce_order_options')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        // 테이블 COMMENT 추가 (MySQL 전용)
        if (DB::getDriverName() == 'mysql') {
            Schema::table('ecommerce_product_reviews', function (Blueprint $table) {
                $table->comment('상품 리뷰 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_product_reviews');
    }
};
