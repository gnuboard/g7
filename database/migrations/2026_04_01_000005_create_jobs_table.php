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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index()->comment('큐 이름');
            $table->longText('payload')->comment('작업 페이로드');
            $table->unsignedTinyInteger('attempts')->comment('시도 횟수');
            $table->unsignedInteger('reserved_at')->nullable()->comment('예약 시간');
            $table->unsignedInteger('available_at')->comment('실행 가능 시간');
            $table->unsignedInteger('created_at')->comment('생성 시간');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('jobs', function (Blueprint $table) {
                $table->comment('작업 큐 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
