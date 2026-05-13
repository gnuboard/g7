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
        Schema::dropIfExists('user_marketing_consent_histories');

        Schema::create('user_marketing_consent_histories', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('사용자 ID');
            $table->string('channel_key', 30)->comment('동의 항목 키 (email_subscription, marketing_consent 등)');
            $table->string('action', 10)->comment('변경 유형 (granted/revoked)');
            $table->string('source', 20)->comment('변경 경로 (register/profile/admin)');
            $table->string('ip_address', 45)->nullable()->comment('IP 주소');
            $table->timestamp('created_at')->nullable()->comment('생성 일시');

            $table->index(['user_id', 'channel_key']);
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('user_marketing_consent_histories', function (Blueprint $table) {
                $table->comment('마케팅 동의 변경 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_marketing_consent_histories')) {
            Schema::dropIfExists('user_marketing_consent_histories');
        }
    }
};
