<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 영구 계정 잠금 상태를 별도 컬럼으로 분리합니다.
     *
     * 보안 환경설정의 `login_lockout_time = 0` 은 관리자 UI 가 "무한대" 로 안내하는 값이지만
     * `locked_until` 은 이미 `NULL = 잠금 없음` 규약을 점유하고 있어 NULL 을 "무기한" 으로
     * 재사용할 수 없고, timestamp 상한(2038)으로 센티넬 시각도 쓸 수 없습니다.
     * 따라서 영구 잠금 여부를 명시 컬럼으로 분리합니다.
     *
     * - locked_permanently: true 면 `locked_until` 과 무관하게 잠금 유지 (해제는 성공 로그인 또는 관리자 수동 해제)
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'locked_permanently')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('locked_permanently')
                ->default(false)
                ->after('locked_until')
                ->comment('영구 잠금 여부 (잠금 시간 0 = 무한대 설정으로 잠긴 계정)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'locked_permanently')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locked_permanently');
        });
    }
};
