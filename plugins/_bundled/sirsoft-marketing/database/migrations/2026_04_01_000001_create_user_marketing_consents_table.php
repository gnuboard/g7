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
        Schema::dropIfExists('user_marketing_consents');

        Schema::create('user_marketing_consents', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('사용자 ID');
            $table->string('consent_key', 30)->comment('동의 항목 키 (email_subscription, marketing_consent 등)');
            $table->boolean('is_consented')->default(false)->comment('현재 동의 여부');
            $table->timestamp('consented_at')->nullable()->comment('최근 동의 일시');
            $table->timestamp('revoked_at')->nullable()->comment('최근 철회 일시');
            $table->unsignedInteger('consent_count')->default(0)->comment('총 동의 횟수');
            $table->string('last_source', 20)->nullable()->comment('최근 변경 경로 (register/profile/admin)');
            $table->timestamps();

            $table->unique(['user_id', 'consent_key']);
            $table->index(['consent_key', 'is_consented']);
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('user_marketing_consents', function (Blueprint $table) {
                $table->comment('사용자 마케팅 동의 현황');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_marketing_consents')) {
            Schema::dropIfExists('user_marketing_consents');
        }
    }
};
