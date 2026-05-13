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
        Schema::create('ecommerce_order_options', function (Blueprint $table) {
            $table->id()->comment('주문 옵션 ID');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('parent_option_id')->nullable()->comment('추가 옵션/구성품의 부모 옵션 ID');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_option_id');
            $table->string('option_status', 30)->comment('옵션 상태 (OrderStatusEnum)');
            $table->boolean('is_stock_deducted')->default(false)->comment('재고 차감 여부');
            $table->string('source_type', 20)->default('order')->comment('생성 원인 (order/exchange)');
            $table->unsignedBigInteger('source_option_id')->nullable()->comment('교환 원본 옵션 ID');
            $table->string('sku', 100)->nullable()->comment('SKU (주문 시점, 검색용)');
            $table->text('product_name')->comment('상품명 (다국어, 주문 시점)');
            $table->string('product_option_name', 255)->nullable()->comment('옵션 조합명 (예: "빨강 / XL")');
            $table->string('option_name', 255)->nullable()->comment('옵션명 (예: "색상", "사이즈")');
            $table->string('option_value', 255)->nullable()->comment('옵션값 (예: "빨강", "XL")');
            $table->integer('quantity')->comment('주문수량');
            $table->decimal('unit_weight', 10, 3)->nullable()->comment('단위 무게 (kg, 주문 시점)');
            $table->decimal('unit_volume', 10, 3)->nullable()->comment('단위 부피 (cm³, 주문 시점)');
            $table->decimal('subtotal_weight', 10, 3)->nullable()->comment('무게 소계 (unit_weight × quantity)');
            $table->decimal('subtotal_volume', 10, 3)->nullable()->comment('부피 소계 (unit_volume × quantity)');
            $table->decimal('unit_price', 12, 2)->comment('단가 (주문 시점 가격)');
            $table->decimal('subtotal_price', 12, 2)->comment('소계 (unit_price × quantity)');
            $table->decimal('subtotal_discount_amount', 12, 2)->default(0)->comment('할인 소계 (아래 할인 합계)');
            $table->decimal('coupon_discount_amount', 12, 2)->default(0)->comment('쿠폰 할인');
            $table->decimal('product_coupon_discount_amount', 12, 2)->default(0)->comment('상품 쿠폰 할인 (상품/카테고리 쿠폰)');
            $table->decimal('order_coupon_discount_amount', 12, 2)->default(0)->comment('주문 쿠폰 할인 안분액');
            $table->decimal('code_discount_amount', 12, 2)->default(0)->comment('할인코드 할인');
            $table->decimal('subtotal_points_used_amount', 12, 2)->default(0)->comment('포인트 사용 소계 (옵션별 배분)');
            $table->decimal('subtotal_deposit_used_amount', 12, 2)->default(0)->comment('예치금 사용 소계 (옵션별 배분)');
            $table->decimal('subtotal_paid_amount', 12, 2)->default(0)->comment('실결제 소계 (옵션별 배분)');
            $table->decimal('subtotal_tax_amount', 12, 2)->default(0)->comment('과세 소계');
            $table->decimal('subtotal_tax_free_amount', 12, 2)->default(0)->comment('면세 소계');
            $table->decimal('subtotal_earned_points_amount', 12, 2)->default(0)->comment('적립 예정 포인트 소계');
            $table->text('mc_unit_price')->nullable()->comment('단가 다중 통화');
            $table->text('mc_subtotal_price')->nullable()->comment('소계 다중 통화');
            $table->text('mc_product_coupon_discount_amount')->nullable()->comment('상품 쿠폰 할인 다중 통화');
            $table->text('mc_order_coupon_discount_amount')->nullable()->comment('주문 쿠폰 안분 다중 통화');
            $table->text('mc_coupon_discount_amount')->nullable()->comment('쿠폰 할인 합계 다중 통화');
            $table->text('mc_code_discount_amount')->nullable()->comment('할인코드 할인 다중 통화');
            $table->text('mc_subtotal_points_used_amount')->nullable()->comment('포인트 사용 다중 통화');
            $table->text('mc_subtotal_deposit_used_amount')->nullable()->comment('예치금 사용 다중 통화');
            $table->text('mc_subtotal_tax_amount')->nullable()->comment('과세 소계 다중 통화');
            $table->text('mc_subtotal_tax_free_amount')->nullable()->comment('면세 소계 다중 통화');
            $table->text('mc_final_amount')->nullable()->comment('최종금액 다중 통화');
            $table->mediumText('product_snapshot')->comment('상품 스냅샷 (상품 변경/삭제 대응)');
            $table->mediumText('option_snapshot')->comment('옵션 스냅샷 (옵션 변경/삭제 대응)');
            $table->mediumText('promotions_applied_snapshot')->nullable()->comment('적용 프로모션 (개별 할인 재계산)');
            $table->string('external_option_id', 100)->nullable()->comment('외부 옵션 ID (네이버페이 등)');
            $table->mediumText('external_meta')->nullable()->comment('외부 연동 메타 정보');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_options_order_id_index');
            $table->index('product_id', 'ecommerce_order_options_product_id_index');
            $table->index('option_status', 'ecommerce_order_options_option_status_index');
            $table->index('sku', 'ecommerce_order_options_sku_index');
            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->cascadeOnDelete();
            $table->foreign('parent_option_id')->references('id')->on('ecommerce_order_options')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('ecommerce_products')->restrictOnDelete();
            $table->foreign('product_option_id')->references('id')->on('ecommerce_product_options')->restrictOnDelete();
            $table->foreign('source_option_id')->references('id')->on('ecommerce_order_options')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_options` COMMENT '주문 옵션 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_options')) {
            Schema::dropIfExists('ecommerce_order_options');
        }
    }
};
