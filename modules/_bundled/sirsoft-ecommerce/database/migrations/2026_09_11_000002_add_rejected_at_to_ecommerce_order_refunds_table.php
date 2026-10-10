<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 환불신청서에 반려 시각(`rejected_at`) 컬럼을 추가합니다. (#599 후속 F4)
 *
 * 교환·반품 신청서에는 승인·반려·철회 시각이 모두 있는데 환불만 반려 시각이 없었다 —
 * 컬럼 자체가 스키마에 없어 `stampFor()` 가 반려 시 `reject_reason` 만 찍었다. 그 결과
 * 상세 화면의 "승인·반려·철회 일시" 자리에서 환불 유형만 영영 빈칸이 된다(F16 과 짝).
 *
 * 자리는 `withdrawn_at` 뒤다 — 형제 세 컬럼(`approved_at`·`on_hold_at`·`withdrawn_at`)과
 * 같은 구역에 모아 두어야 스키마를 읽는 사람이 한 축임을 알아본다.
 *
 * 백필은 하지 않는다(D20 — 개발환경·릴리즈 전). 이미 반려된 구형 행의 반려 시각은 복원할
 * 근거가 없다(`updated_at` 은 그 뒤의 다른 수정으로 덮였을 수 있다) — 화면은 빈칸으로 그린다.
 */
return new class extends Migration
{
    /**
     * 대상 테이블.
     */
    private string $table = 'ecommerce_order_refunds';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable($this->table) || Schema::hasColumn($this->table, 'rejected_at')) {
            return;
        }

        Schema::table($this->table, function (Blueprint $blueprint) {
            $blueprint->timestamp('rejected_at')->nullable()->after('withdrawn_at')
                ->comment('반려 시각');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->table) || ! Schema::hasColumn($this->table, 'rejected_at')) {
            return;
        }

        Schema::table($this->table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('rejected_at');
        });
    }
};
