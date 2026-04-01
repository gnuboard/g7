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
        Schema::create('menu_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('permission_type', ['read', 'write', 'delete'])->default('read')->comment('권한 유형 (read: 읽기, write: 쓰기, delete: 삭제)');
            $table->boolean('is_allowed')->default(true)->comment('허용 여부 (1: 허용, 0: 거부)');
            $table->timestamp('granted_at')->useCurrent()->comment('권한 부여 일시');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['menu_id', 'role_id']);
            $table->index(['menu_id', 'user_id']);
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('menu_permissions', function (Blueprint $table) {
                $table->comment('메뉴별 접근 권한 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_permissions');
    }
};
