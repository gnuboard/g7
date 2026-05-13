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
        Schema::create('user_consents', function (Blueprint $table) {
            $table->id()->comment('동의 이력 ID');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('사용자 ID');
            $table->string('consent_type', 30)->comment('동의 유형: terms, privacy');
            $table->timestamp('agreed_at')->comment('동의 일시');
            $table->timestamp('revoked_at')->nullable()->comment('철회 일시 (향후 플러그인 확장용)');
            $table->string('ip_address', 45)->nullable()->comment('동의 시 IP 주소 (IPv6 대응)');
            $table->timestamp('created_at')->nullable()->comment('생성 일시');

            $table->index(['user_id', 'consent_type'], 'user_consents_user_type_index');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('user_consents', function (Blueprint $table) {
                $table->comment('사용자 약관 동의 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
