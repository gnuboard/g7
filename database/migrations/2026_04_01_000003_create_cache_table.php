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
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('캐시 키');
            $table->mediumText('value')->comment('캐시 값');
            $table->integer('expiration')->comment('만료 시간');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('cache', function (Blueprint $table) {
                $table->comment('시스템 캐시 데이터');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
    }
};
