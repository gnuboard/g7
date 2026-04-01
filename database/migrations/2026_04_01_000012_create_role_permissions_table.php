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
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->enum('scope_type', ['self', 'role'])->nullable()->comment('접근 스코프 (null: 전체, self: 본인, role: 소유역할)');
            $table->timestamp('granted_at')->useCurrent()->comment('권한 부여 일시');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('role_permissions', function (Blueprint $table) {
                $table->comment('역할-권한 관계 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
