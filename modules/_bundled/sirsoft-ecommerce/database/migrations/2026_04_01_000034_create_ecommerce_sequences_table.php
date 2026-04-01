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
        Schema::create('ecommerce_sequences', function (Blueprint $table) {
            $table->id()->comment('시퀀스 ID');
            $table->string('type', 50)->comment('시퀀스 타입 (product: 상품, order: 주문, shipping: 배송)');
            $table->string('algorithm', 20)->default('sequential')->comment('채번 알고리즘 (hybrid: 타임스탬프+시퀀스, sequential: 순수시퀀스, daily: 일별리셋)');
            $table->string('prefix', 20)->nullable()->comment('코드 접두사');
            $table->unsignedBigInteger('current_value')->default(0)->comment('현재 시퀀스 값');
            $table->unsignedInteger('increment')->default(1)->comment('증가 단위');
            $table->unsignedBigInteger('min_value')->default(1)->comment('최소값');
            $table->unsignedBigInteger('max_value')->default(9999999999)->comment('최대값 (10자리)');
            $table->boolean('cycle')->default(false)->comment('순환 여부 (1: max 도달 시 min으로 순환, 0: 비순환)');
            $table->unsignedInteger('pad_length')->default(10)->comment('자릿수 패딩 (10: 0000000001 형식)');
            $table->unsignedInteger('max_history_count')->default(0)->comment('코드 이력 최대 보관 건수 (0: 무제한)');
            $table->string('date_format', 20)->nullable()->comment('날짜 형식 (일별 리셋 시 사용, 예: Ymd)');
            $table->date('last_reset_date')->nullable()->comment('마지막 리셋 날짜 (일별 리셋용)');
            $table->timestamps();

            $table->unique(['type', 'last_reset_date'], 'ecommerce_sequences_type_date_unique');
            $table->index('type', 'ecommerce_sequences_type_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_sequences` COMMENT '채번 시퀀스 정보'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_sequences')) {
            Schema::dropIfExists('ecommerce_sequences');
        }
    }
};
