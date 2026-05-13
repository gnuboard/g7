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
        Schema::create('ecommerce_orders', function (Blueprint $table) {
            $table->id()->comment('주문 ID');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('order_number', 50)->unique()->comment('주문번호');
            $table->string('order_status', 30)->comment('주문상태 (OrderStatusEnum)');
            $table->string('order_device', 20)->nullable()->comment('주문 디바이스 (pc/mobile/app)');
            $table->boolean('is_first_order')->default(false)->comment('첫구매 여부 (1: 첫구매, 0: 재구매)');
            $table->string('ip_address', 45)->nullable()->comment('주문자 IP (IPv6 대응)');
            $table->string('currency', 10)->default('KRW')->comment('결제 통화 (KRW, USD, EUR 등)');
            $table->mediumText('currency_snapshot')->nullable()->comment('주문 시점 통화 스냅샷 (모든 통화 환율)');
            $table->decimal('subtotal_amount', 12, 2)->comment('상품 합계 (할인 전, 상품가×수량 합계)');
            $table->decimal('total_discount_amount', 12, 2)->default(0)->comment('총 할인금액 (모든 할인 합계)');
            $table->decimal('total_coupon_discount_amount', 12, 2)->default(0)->comment('총 쿠폰 할인금액');
            $table->decimal('total_product_coupon_discount_amount', 12, 2)->default(0)->comment('상품 쿠폰 할인 합계');
            $table->decimal('total_order_coupon_discount_amount', 12, 2)->default(0)->comment('주문 쿠폰 할인 합계');
            $table->decimal('total_code_discount_amount', 12, 2)->default(0)->comment('총 할인코드 할인금액');
            $table->decimal('total_shipping_amount', 12, 2)->default(0)->comment('총 배송비');
            $table->decimal('base_shipping_amount', 12, 2)->default(0)->comment('기본 배송비');
            $table->decimal('extra_shipping_amount', 12, 2)->default(0)->comment('추가 배송비 (도서산간)');
            $table->decimal('shipping_discount_amount', 12, 2)->default(0)->comment('배송비 할인금액');
            $table->decimal('total_amount', 12, 2)->comment('최종 주문금액 (subtotal - discount + shipping)');
            $table->decimal('total_tax_amount', 12, 2)->default(0)->comment('총 과세금액');
            $table->decimal('total_tax_free_amount', 12, 2)->default(0)->comment('총 면세금액');
            $table->decimal('total_points_used_amount', 12, 2)->default(0)->comment('총 포인트 사용액');
            $table->decimal('total_deposit_used_amount', 12, 2)->default(0)->comment('총 예치금 사용액');
            $table->decimal('total_paid_amount', 12, 2)->default(0)->comment('총 실제 결제금액 (PG 결제액)');
            $table->decimal('total_due_amount', 12, 2)->default(0)->comment('총 결제예정금액 (무통장 등)');
            $table->decimal('total_cancelled_amount', 12, 2)->default(0)->comment('총 취소금액');
            $table->decimal('total_refunded_amount', 12, 2)->default(0)->comment('총 환불금액');
            $table->decimal('total_refunded_points_amount', 12, 2)->default(0)->comment('총 환불 포인트');
            $table->decimal('total_earned_points_amount', 12, 2)->default(0)->comment('총 적립 예정 포인트');
            $table->integer('item_count')->comment('총 주문수량 (상품 수량 합계)');
            $table->decimal('total_weight', 10, 3)->nullable()->comment('총 무게 (kg)');
            $table->decimal('total_volume', 10, 3)->nullable()->comment('총 부피 (cm³)');
            $table->timestamp('ordered_at')->comment('주문일시');
            $table->timestamp('paid_at')->nullable()->comment('결제완료일시');
            $table->timestamp('payment_due_at')->nullable()->comment('결제마감일시 (무통장 입금기한)');
            $table->timestamp('confirmed_at')->nullable()->comment('구매확정일시');
            $table->timestamps();
            $table->softDeletes()->comment('삭제일시 (Soft Delete)');
            $table->text('admin_memo')->nullable()->comment('관리자 메모 (내부 관리용)');
            $table->mediumText('promotions_applied_snapshot')->nullable()->comment('적용된 프로모션 스냅샷 (재계산용)');
            $table->mediumText('shipping_policy_applied_snapshot')->nullable()->comment('적용된 배송정책 스냅샷 (재계산용)');
            $table->mediumText('promotions_available_snapshot')->nullable()->comment('사용가능 프로모션 스냅샷 (감사용)');
            $table->mediumText('order_meta')->nullable()->comment('기타 메타정보 (확장성)');
            $table->text('mc_subtotal_amount')->nullable()->comment('상품합계 다중 통화');
            $table->text('mc_total_product_coupon_discount_amount')->nullable()->comment('상품 쿠폰 할인 다중 통화');
            $table->text('mc_total_order_coupon_discount_amount')->nullable()->comment('주문 쿠폰 할인 다중 통화');
            $table->text('mc_total_coupon_discount_amount')->nullable()->comment('쿠폰 할인 합계 다중 통화');
            $table->text('mc_total_code_discount_amount')->nullable()->comment('할인코드 할인 다중 통화');
            $table->text('mc_total_discount_amount')->nullable()->comment('총 할인 다중 통화');
            $table->text('mc_base_shipping_amount')->nullable()->comment('기본 배송비 다중 통화');
            $table->text('mc_extra_shipping_amount')->nullable()->comment('추가 배송비 다중 통화');
            $table->text('mc_total_shipping_amount')->nullable()->comment('총 배송비 다중 통화');
            $table->text('mc_shipping_discount_amount')->nullable()->comment('배송비 할인 다중 통화');
            $table->text('mc_total_tax_amount')->nullable()->comment('과세금액 다중 통화');
            $table->text('mc_total_tax_free_amount')->nullable()->comment('면세금액 다중 통화');
            $table->text('mc_total_points_used_amount')->nullable()->comment('포인트 사용 다중 통화');
            $table->text('mc_total_deposit_used_amount')->nullable()->comment('예치금 사용 다중 통화');
            $table->text('mc_total_amount')->nullable()->comment('최종금액 다중 통화 (payment_amount)');
            $table->text('mc_total_paid_amount')->nullable()->comment('실결제금액 다중 통화');

            $table->index('user_id', 'ecommerce_orders_user_id_index');
            $table->index('order_status', 'ecommerce_orders_order_status_index');
            $table->index('ordered_at', 'ecommerce_orders_ordered_at_index');
            $table->index('paid_at', 'ecommerce_orders_paid_at_index');
            $table->index(['order_status', 'ordered_at'], 'ecommerce_orders_status_ordered_at_index');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_orders` COMMENT '주문 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_orders')) {
            Schema::dropIfExists('ecommerce_orders');
        }
    }
};
