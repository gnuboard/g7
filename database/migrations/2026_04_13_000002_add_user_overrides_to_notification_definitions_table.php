<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * notification_definitions 테이블에 user_overrides 컬럼 추가.
     *
     * 사용자가 수정한 필드명 목록을 JSON 으로 저장하여 시더 재실행 시 보존합니다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('notification_definitions', function (Blueprint $table) {
            $table->text('user_overrides')->nullable()->after('is_default')
                ->comment('유저가 수정한 필드명 목록 (예: ["name", "is_active"])');
        });
    }

    /**
     * user_overrides 컬럼 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasColumn('notification_definitions', 'user_overrides')) {
            return;
        }

        Schema::table('notification_definitions', function (Blueprint $table) {
            $table->dropColumn('user_overrides');
        });
    }
};
