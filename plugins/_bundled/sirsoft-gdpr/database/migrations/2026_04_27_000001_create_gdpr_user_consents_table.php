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
        Schema::dropIfExists('gdpr_user_consents');

        Schema::create('gdpr_user_consents', function (Blueprint $table) {
            $table->id()->comment('행 ID');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('사용자 ID');
            $table->string('consent_key', 50)->comment('동의 항목 키 (cookie_necessary 등)');
            $table->string('consent_category', 30)->nullable()->comment('동의 분류 (cookie 등)');
            $table->boolean('is_consented')->default(false)->comment('현재 동의 여부');
            $table->timestamp('consented_at')->nullable()->comment('최근 동의 일시');
            $table->timestamp('revoked_at')->nullable()->comment('최근 철회 일시');
            $table->unsignedInteger('consent_count')->default(0)->comment('총 동의 횟수');
            $table->string('policy_version', 20)->nullable()->comment('최근 동의 시점 정책 버전 스냅샷');
            $table->string('last_source', 20)->nullable()->comment('최근 변경 경로 (banner/preference_center/register/mypage)');
            $table->timestamps();

            $table->unique(['user_id', 'consent_key']);
            $table->index(['consent_key', 'is_consented']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('gdpr_user_consents', function (Blueprint $table) {
                $table->comment('GDPR 사용자 현재 동의 상태');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gdpr_user_consents')) {
            Schema::dropIfExists('gdpr_user_consents');
        }
    }
};
