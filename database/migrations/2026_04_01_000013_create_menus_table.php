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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->text('name')->comment('메뉴 이름 (다국어 JSON)');
            $table->string('slug')->unique()->comment('메뉴 슬러그');
            $table->string('url')->nullable()->comment('메뉴 URL');
            $table->string('icon')->nullable()->comment('메뉴 아이콘');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('상위 메뉴 ID');
            $table->integer('order')->default(0)->comment('메뉴 순서');
            $table->boolean('is_active')->default(true)->comment('활성 상태 (1: 활성, 0: 비활성)');
            $table->string('extension_type', 20)->nullable()->comment('확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의)');
            $table->string('extension_identifier', 255)->nullable()->comment('확장 식별자 (예: core, sirsoft-board, sirsoft-payment)');
            $table->text('user_overrides')->nullable()->comment('유저가 수정한 필드명 목록 (예: ["name", "icon", "order"])');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->comment('등록자 ID');
            $table->timestamps();

            $table->index(['parent_id', 'order']);
            $table->index(['is_active', 'order']);
            $table->index(['extension_type', 'extension_identifier'], 'idx_menus_extension');
            $table->foreign('parent_id')->references('id')->on('menus')->cascadeOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('menus', function (Blueprint $table) {
                $table->comment('시스템 메뉴 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
