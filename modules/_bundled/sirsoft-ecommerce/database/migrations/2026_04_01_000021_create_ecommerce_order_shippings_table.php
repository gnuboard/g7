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
        Schema::create('ecommerce_order_shippings', function (Blueprint $table) {
            $table->id()->comment('배송 ID');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_option_id');
            $table->unsignedBigInteger('shipping_policy_id')->nullable()->comment('배송정책 ID');
            $table->string('shipping_status', 30)->comment('배송 상태 (ShippingStatusEnum)');
            $table->string('shipping_type', 30)->comment('배송유형 (ShippingTypeEnum, 국내/해외 구분 포함)');
            $table->decimal('base_shipping_amount', 12, 2)->default(0)->comment('배송비');
            $table->decimal('extra_shipping_amount', 12, 2)->default(0)->comment('추가 배송비 (도서산간)');
            $table->decimal('total_shipping_amount', 12, 2)->default(0)->comment('총 배송비 (기본 + 추가)');
            $table->decimal('shipping_discount_amount', 12, 2)->default(0)->comment('배송비 할인금액');
            $table->boolean('is_remote_area')->default(false)->comment('도서산간 여부');
            $table->unsignedBigInteger('carrier_id')->nullable()->comment('택배사 ID (국내/해외 공용)');
            $table->string('carrier_name', 100)->nullable()->comment('택배사명 (주문 시점 스냅샷)');
            $table->string('tracking_number', 50)->nullable()->comment('운송장 번호');
            $table->decimal('return_shipping_amount', 12, 2)->default(0)->comment('반품 배송비');
            $table->unsignedBigInteger('return_carrier_id')->nullable()->comment('반품 택배사 ID');
            $table->string('return_tracking_number', 50)->nullable()->comment('반품 운송장 번호');
            $table->unsignedBigInteger('exchange_carrier_id')->nullable()->comment('교환 택배사 ID');
            $table->string('exchange_tracking_number', 50)->nullable()->comment('교환 운송장 번호');
            $table->string('package_number', 50)->nullable()->comment('합포장 번호 (동일 번호 = 합포장)');
            $table->date('visit_date')->nullable()->comment('방문수령일');
            $table->string('visit_time_slot', 30)->nullable()->comment('방문시간대');
            $table->decimal('actual_weight', 10, 3)->nullable()->comment('실측 무게 (kg)');
            $table->mediumText('delivery_policy_snapshot')->nullable()->comment('배송정책 스냅샷 (주문시점 조건)');
            $table->mediumText('currency_snapshot')->nullable()->comment('배송비 계산 시점 통화 스냅샷');
            $table->text('mc_base_shipping_amount')->nullable()->comment('배송비 다중 통화');
            $table->text('mc_extra_shipping_amount')->nullable()->comment('추가 배송비 다중 통화');
            $table->text('mc_total_shipping_amount')->nullable()->comment('총 배송비 다중 통화');
            $table->text('mc_shipping_discount_amount')->nullable()->comment('배송비 할인 다중 통화');
            $table->text('mc_return_shipping_amount')->nullable()->comment('반품 배송비 다중 통화');
            $table->timestamp('shipped_at')->nullable()->comment('발송일시');
            $table->timestamp('estimated_arrival_at')->nullable()->comment('예상 도착일시');
            $table->timestamp('delivered_at')->nullable()->comment('배송완료일시');
            $table->timestamp('confirmed_at')->nullable()->comment('구매확정일시');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_shippings_order_id_index');
            $table->index('order_option_id', 'ecommerce_order_shippings_order_option_id_index');
            $table->index('shipping_status', 'ecommerce_order_shippings_shipping_status_index');
            $table->index('shipping_type', 'ecommerce_order_shippings_shipping_type_index');
            $table->index('tracking_number', 'ecommerce_order_shippings_tracking_number_index');
            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->cascadeOnDelete();
            $table->foreign('order_option_id')->references('id')->on('ecommerce_order_options')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_shippings` COMMENT '주문 배송 정보'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_shippings')) {
            Schema::dropIfExists('ecommerce_order_shippings');
        }
    }
};
