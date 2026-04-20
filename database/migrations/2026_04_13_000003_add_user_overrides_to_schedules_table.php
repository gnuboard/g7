<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * schedules 테이블에 user_overrides 컬럼 추가 (사전 설계).
     *
     * 현재는 시더 없이 사용자 수동 생성 중심이지만, 향후 코어/모듈이 기본 스케줄
     * 시더를 도입하면 syncOrCreateFromUpgrade 호출만 추가하면 됩니다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->text('user_overrides')->nullable()->after('is_active')
                ->comment('유저가 수정한 필드명 목록 (예: ["expression", "command", "timeout"])');
        });
    }

    /**
     * user_overrides 컬럼 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('schedules', 'user_overrides')) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('user_overrides');
        });
    }
};
