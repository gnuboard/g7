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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique()->comment('역할명 (예: admin, user, manager)');
            $table->text('name')->comment('역할 이름 (다국어 JSON)');
            $table->mediumText('description')->nullable()->comment('역할 설명 (다국어 JSON)');
            $table->string('extension_type', 20)->nullable()->comment('확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의)');
            $table->string('extension_identifier', 255)->nullable()->comment('확장 식별자 (예: core, sirsoft-board, sirsoft-payment)');
            $table->text('user_overrides')->nullable()->comment('유저가 수정한 필드명 목록 (예: ["name", "permissions"])');
            $table->boolean('is_active')->default(true)->comment('활성화 상태 (1: 활성, 0: 비활성)');
            $table->timestamps();

            $table->index(['extension_type', 'extension_identifier'], 'idx_roles_extension');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('roles', function (Blueprint $table) {
                $table->comment('사용자 역할 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
