<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 회원 화면 잔액 주입을 O(1) 단일 행 조회로 전환하는 파생 캐시 테이블.
        // 진실의 원천(SSoT)은 ecommerce_mileage_transactions(원장)이며, 본 테이블은 표시 전용 캐시.
        Schema::create('ecommerce_mileage_balances', function (Blueprint $table) {
            $table->id()->comment('잔액 캐시 ID');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('회원 ID');
            $table->string('currency', 10)->default('KRW')->comment('통화 코드 (통화별 행)');
            $table->decimal('available', 12, 2)->default(0)->comment('사용 가능 잔액 (활성 lot SUM 스냅샷)');
            $table->decimal('pending', 12, 2)->default(0)->comment('적립 예정 (미취소·earn ledger 부재 옵션 적립액 합)');
            $table->decimal('total_earned', 12, 2)->default(0)->comment('누적 적립');
            $table->decimal('total_used', 12, 2)->default(0)->comment('누적 사용');
            $table->decimal('expiring_soon', 12, 2)->default(0)->comment('N일내 소멸 예정 (일배치 갱신)');
            $table->timestamp('expiring_date')->nullable()->comment('가장 임박한 소멸 예정일');
            $table->timestamp('recalculated_at')->nullable()->comment('마지막 전체 재계산 시각 (drift 감사)');
            $table->timestamps();

            // 유니크 — 인덱스명 약어 금지(전체 이름)
            $table->unique(['user_id', 'currency'], 'ecommerce_mileage_balances_user_currency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_mileage_balances');
    }
};
