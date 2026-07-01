<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 대시보드용 일별 판매 집계 테이블.
     * 스케쥴러(sirsoft-ecommerce:aggregate-stats)가 하루 1행씩 upsert 하며,
     * 대시보드 API 는 원본(주문/주문상품) 풀스캔 없이 이 테이블만 읽는다.
     *
     * 판매 수량/순매출은 주문상품(order_options)의 매출 반영 상태(option_status)
     * 옵션만 합산하며, 날짜는 주문(orders.ordered_at) 기준으로 귀속한다.
     */
    public function up(): void
    {
        Schema::create('ecommerce_stats', function (Blueprint $table) {
            $table->id()->comment('집계 행 ID');
            $table->date('date')->unique()->comment('집계 기준 날짜 (하루 1행, 멱등 upsert 키)');
            $table->unsignedInteger('sales_quantity')->default(0)->comment('해당 날짜 판매 수량 (매출 반영 상태 옵션의 유효수량 합)');
            $table->decimal('sales_amount', 15, 2)->default(0)->comment('해당 날짜 상품 순매출 (매출 반영 상태 옵션의 unit_price × 유효수량 합)');
            $table->json('option_status_counts')->nullable()->comment('상태별 당일 판매 수량 (option_status 7버킷)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_stats');
    }
};
