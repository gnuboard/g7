<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 보안 환경설정의 로그인 시도 제한·차단 시간을 실제로 강제하기 위한
     * users 테이블 컬럼 3종을 추가합니다.
     *
     * - failed_login_attempts: 연속 실패 카운트 (성공 또는 잠금 시 0 으로 리셋)
     * - locked_until: 계정 잠금 해제 시각 (NULL = 잠금 없음)
     * - last_failed_login_at: 마지막 실패 시각 (감사 로그 / 카운트 윈도우 판정 보조)
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_attempts')
                ->default(0)
                ->after('blocked_at')
                ->comment('연속 로그인 실패 횟수');

            $table->timestamp('locked_until')
                ->nullable()
                ->after('failed_login_attempts')
                ->comment('계정 잠금 해제 시각 (NULL = 잠금 없음)');

            $table->timestamp('last_failed_login_at')
                ->nullable()
                ->after('locked_until')
                ->comment('마지막 로그인 실패 시각');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'failed_login_attempts',
                'locked_until',
                'last_failed_login_at',
            ]);
        });
    }
};
