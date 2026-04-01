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
        Schema::create('role_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->enum('permission_type', ['read', 'write', 'delete'])->default('read')->comment('권한 타입 (read: 읽기/접근, write: 수정, delete: 삭제)');
            $table->timestamps();

            $table->unique(['role_id', 'menu_id', 'permission_type'], 'uk_role_menu_permission');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('role_menus', function (Blueprint $table) {
                $table->comment('역할별 메뉴 접근 권한 (피벗 테이블)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_menus');
    }
};
