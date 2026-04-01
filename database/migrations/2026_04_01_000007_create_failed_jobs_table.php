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
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique()->comment('고유 식별자');
            $table->text('connection')->comment('연결 정보');
            $table->text('queue')->comment('큐 이름');
            $table->longText('payload')->comment('작업 페이로드');
            $table->longText('exception')->comment('예외 정보');
            $table->timestamp('failed_at')->useCurrent()->comment('실패 시간');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('failed_jobs', function (Blueprint $table) {
                $table->comment('실패한 작업 로그');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
