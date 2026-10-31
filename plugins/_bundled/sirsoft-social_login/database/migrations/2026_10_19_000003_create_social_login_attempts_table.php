<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 소셜 로그인 시도 1건. 통계·진단 화면의 원천이라 회원이 지워져도 행을 남긴다 — user_id 에 FK 를 걸지 않는다.
     * 보존 기간 밖 행은 sirsoft-social_login:prune 배치가 지운다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('social_login_attempts', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('provider_key', 50)->comment('제공자 키');
            $table->unsignedBigInteger('user_id')->nullable()->comment('관련 회원 ID — FK 없음(통계 보존)');
            $table->string('intent', 10)->comment('의도 — login·link·test');
            $table->string('outcome', 20)->comment('결과 — login·signup·link·pending·handoff·denied·failed');
            $table->string('error_code', 50)->nullable()->comment('오류 코드 (실패 시)');
            $table->string('error_message', 500)->nullable()->comment('오류 요약 (비밀 제외)');
            $table->string('ip', 45)->nullable()->comment('요청 IP');
            $table->string('user_agent', 500)->nullable()->comment('요청 User-Agent');
            $table->timestamp('created_at')->useCurrent()->comment('시도 시각');

            $table->index(['provider_key', 'created_at'], 'social_login_attempts_provider_created_index');
            $table->index(['outcome', 'created_at'], 'social_login_attempts_outcome_created_index');
            $table->index('user_id', 'social_login_attempts_user_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('social_login_attempts', function (Blueprint $table) {
                $table->comment('소셜 로그인 시도 기록');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('social_login_attempts');
    }
};
