<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 알림 정의 테이블 생성.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('notification_definitions', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('type', 100)->unique()->comment('알림 타입 (welcome, order_confirmed 등)');
            $table->string('hook_prefix', 100)->comment('훅 접두사 (core.auth, sirsoft-ecommerce 등)');
            $table->string('extension_type', 20)->comment('확장 타입: core, module, plugin');
            $table->string('extension_identifier', 100)->comment('확장 식별자: core, sirsoft-board 등');
            $table->text('name')->comment('다국어 이름 ({"ko": "회원가입 환영", "en": "Welcome"})');
            $table->mediumText('description')->nullable()->comment('다국어 설명');
            $table->text('variables')->nullable()->comment('사용 가능 변수 메타데이터 ([{key, description}])');
            $table->text('channels')->comment('활성 채널 (["mail", "database"])');
            $table->text('hooks')->comment('트리거 훅 목록 (["core.auth.after_register"])');
            $table->boolean('is_active')->default(true)->comment('활성 여부');
            $table->boolean('is_default')->default(true)->comment('시더 생성 여부');
            $table->timestamps();
        });
    }

    /**
     * 알림 정의 테이블 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_definitions');
    }
};
