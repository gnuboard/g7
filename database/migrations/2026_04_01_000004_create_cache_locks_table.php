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
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('락 키');
            $table->string('owner')->comment('락 소유자');
            $table->integer('expiration')->comment('만료 시간');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('cache_locks', function (Blueprint $table) {
                $table->comment('캐시 락 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
    }
};
