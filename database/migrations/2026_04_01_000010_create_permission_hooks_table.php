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
        Schema::create('permission_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('hook_name')->index()->comment('훅 이름 (예: core.attachment.download)');
            $table->timestamps();

            $table->unique(['permission_id', 'hook_name']);
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('permission_hooks', function (Blueprint $table) {
                $table->comment('권한-훅 매핑 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_hooks');
    }
};
