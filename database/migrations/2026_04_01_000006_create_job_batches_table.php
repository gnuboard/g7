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
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary()->comment('배치 ID');
            $table->string('name')->comment('배치 이름');
            $table->integer('total_jobs')->comment('전체 작업 수');
            $table->integer('pending_jobs')->comment('대기 중인 작업 수');
            $table->integer('failed_jobs')->comment('실패한 작업 수');
            $table->longText('failed_job_ids')->comment('실패한 작업 ID 목록');
            $table->mediumText('options')->nullable()->comment('배치 옵션');
            $table->integer('cancelled_at')->nullable()->comment('취소 시간');
            $table->integer('created_at')->comment('생성 시간');
            $table->integer('finished_at')->nullable()->comment('완료 시간');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('job_batches', function (Blueprint $table) {
                $table->comment('작업 배치 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
