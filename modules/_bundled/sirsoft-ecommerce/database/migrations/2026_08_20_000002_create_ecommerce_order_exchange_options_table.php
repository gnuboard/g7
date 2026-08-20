<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 교환 신청 상품(옵션별 상세) 테이블을 생성합니다.
 *
 * 신청 1건에 상품 N건이 담기며, 상품 단위 상태 전이(관리자 일괄 변경)를 위해
 * 신청서와 같은 ExchangeStatusEnum 을 옵션에도 둔다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_order_exchange_options', function (Blueprint $table) {
            $table->id()->comment('교환 옵션 ID');
            $table->foreignId('order_exchange_id')->comment('교환 FK')
                ->constrained('ecommerce_order_exchanges')->cascadeOnDelete();
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->foreignId('order_option_id')->comment('원 주문옵션 FK')
                ->constrained('ecommerce_order_options')->cascadeOnDelete();
            $table->string('option_status', 30)->comment('상품 단위 교환 상태 (ExchangeStatusEnum)');

            // 수량 / 원 가격
            $table->unsignedInteger('exchange_quantity')->comment('교환 신청 수량');
            $table->unsignedInteger('original_quantity')->comment('신청 전 원래 수량 (감사용)');
            $table->decimal('unit_price', 12, 2)->comment('신청 시점 원 옵션 단가');

            // 신규 옵션
            $table->unsignedBigInteger('new_product_option_id')->nullable()->comment('교환 대상 신규 상품옵션 ID (동일 상품 재발송이면 NULL)');
            $table->mediumText('new_option_snapshot')->nullable()->comment('신규 옵션 스냅샷 JSON (커스텀 옵션 입력값 포함)');
            $table->decimal('new_unit_price', 12, 2)->nullable()->comment('신규 옵션 단가');
            $table->decimal('price_difference', 12, 2)->default(0)->comment('(신규단가 − 원단가) × 수량, 음수면 환불 대상');
            $table->text('mc_price_difference')->nullable()->comment('다통화 옵션 차액 (통화코드 → 금액)');

            // 재발송 산출물
            $table->unsignedBigInteger('new_order_option_id')->nullable()->comment('재발송으로 생성된 주문옵션 ID (source_type=exchange)');
            $table->boolean('is_new_stock_deducted')->default(false)->comment('신규 옵션 재고 차감 완료 여부');

            $table->timestamp('completed_at')->nullable()->comment('상품 단위 완료 시각');
            $table->foreignId('processed_by')->nullable()->comment('처리 관리자')
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_exchange_id', 'ecommerce_order_ex_opts_exchange_id_index');
            $table->index('order_id', 'ecommerce_order_ex_opts_order_id_index');
            $table->index('order_option_id', 'ecommerce_order_ex_opts_option_id_index');
            $table->index('option_status', 'ecommerce_order_ex_opts_status_index');

            $table->foreign('new_product_option_id', 'fk_ex_opt_new_product_option_id')
                ->references('id')->on('ecommerce_product_options')->nullOnDelete();
            $table->foreign('new_order_option_id', 'fk_ex_opt_new_order_option_id')
                ->references('id')->on('ecommerce_order_options')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.DB::getTablePrefix()."ecommerce_order_exchange_options` COMMENT '교환 신청 상품별 상세'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_exchange_options')) {
            Schema::dropIfExists('ecommerce_order_exchange_options');
        }
    }
};
