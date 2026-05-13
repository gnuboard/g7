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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id()->comment('활동 로그 ID');
            $table->string('log_type', 20)->index()->comment('로그 유형 (admin: 관리자, user: 사용자, system: 시스템)');
            $table->nullableMorphs('loggable');
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete()->comment('사용자 ID');
            $table->string('action', 50)->index()->comment('액션 유형 (created, updated, deleted, login, export 등)');
            $table->text('description')->comment('액션 상세 설명');
            $table->mediumText('properties')->nullable()->comment('변경 상세 데이터 (old/new 값)');
            $table->string('ip_address', 45)->nullable()->comment('IP 주소 (IPv6 대응)');
            $table->string('user_agent', 500)->nullable()->comment('User Agent');
            $table->timestamp('created_at')->useCurrent()->index()->comment('생성일시');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
