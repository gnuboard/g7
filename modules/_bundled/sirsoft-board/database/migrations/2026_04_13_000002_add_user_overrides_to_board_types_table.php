<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * board_types 테이블에 user_overrides 컬럼 추가.
     *
     * 사용자가 수정한 필드명 목록을 저장하여 시더 재실행 시 보존합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_types') || Schema::hasColumn('board_types', 'user_overrides')) {
            return;
        }

        Schema::table('board_types', function (Blueprint $table) {
            $table->text('user_overrides')->nullable()
                ->comment('유저가 수정한 필드명 목록 (예: ["name"])');
        });
    }

    /**
     * user_overrides 컬럼 삭제.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('board_types') || ! Schema::hasColumn('board_types', 'user_overrides')) {
            return;
        }

        Schema::table('board_types', function (Blueprint $table) {
            $table->dropColumn('user_overrides');
        });
    }
};
