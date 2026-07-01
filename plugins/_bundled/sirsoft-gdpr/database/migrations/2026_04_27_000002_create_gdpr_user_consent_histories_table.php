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
        Schema::dropIfExists('gdpr_user_consent_histories');

        Schema::create('gdpr_user_consent_histories', function (Blueprint $table) {
            $table->id()->comment('행 ID');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('사용자 ID (게스트면 NULL, 삭제 시 NULL 익명화)');
            $table->string('session_id', 100)->nullable()->comment('게스트 세션 ID (회원이면 NULL)');
            $table->string('consent_key', 50)->comment('동의 항목 키');
            $table->string('action', 20)->comment('변경 유형 (granted/revoked/acknowledged)');
            $table->string('source', 20)->comment('변경 경로 (banner/preference_center/register/mypage/order/withdraw)');
            $table->string('policy_version', 20)->nullable()->comment('시점 정책 버전');
            $table->json('categories')->nullable()->comment('카테고리 스냅샷');
            $table->string('ip_address', 45)->nullable()->comment('IP 주소 (삭제 시 NULL 익명화)');
            $table->string('user_agent', 500)->nullable()->comment('User-Agent (삭제 시 NULL 익명화)');
            $table->timestamp('created_at')->nullable()->comment('생성 일시 (UPDATED_AT 없음 — 불변 레코드)');

            $table->index(['user_id', 'consent_key']);
            $table->index('session_id');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('gdpr_user_consent_histories', function (Blueprint $table) {
                $table->comment('GDPR 동의 변경 이력 (불변 append-only)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gdpr_user_consent_histories')) {
            Schema::dropIfExists('gdpr_user_consent_histories');
        }
    }
};
