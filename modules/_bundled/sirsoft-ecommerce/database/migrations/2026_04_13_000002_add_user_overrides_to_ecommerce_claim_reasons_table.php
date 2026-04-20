<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ecommerce_claim_reasons 테이블에 user_overrides 컬럼 추가.
     *
     * 사용자가 수정한 필드명 목록을 저장하여 시더 재실행 시 보존합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_claim_reasons') || Schema::hasColumn('ecommerce_claim_reasons', 'user_overrides')) {
            return;
        }

        Schema::table('ecommerce_claim_reasons', function (Blueprint $table) {
            $table->text('user_overrides')->nullable()
                ->comment('유저가 수정한 필드명 목록 (예: ["name", "sort_order", "is_active"])');
        });
    }

    /**
     * user_overrides 컬럼 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_claim_reasons') || ! Schema::hasColumn('ecommerce_claim_reasons', 'user_overrides')) {
            return;
        }

        Schema::table('ecommerce_claim_reasons', function (Blueprint $table) {
            $table->dropColumn('user_overrides');
        });
    }
};
