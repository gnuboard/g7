<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boards 테이블에서 알림 채널 컬럼 삭제.
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (Schema::hasColumn('boards', 'notify_author_channels')) {
                $table->dropColumn('notify_author_channels');
            }
            if (Schema::hasColumn('boards', 'notify_admin_on_post_channels')) {
                $table->dropColumn('notify_admin_on_post_channels');
            }
        });
    }

    /**
     * boards 테이블에 알림 채널 컬럼 복원.
     */
    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (! Schema::hasColumn('boards', 'notify_author_channels')) {
                $table->text('notify_author_channels')->nullable()->after('notify_admin_on_post')->comment('작성자 알림 채널 (배열, 예: ["mail", "database"])');
            }
            if (! Schema::hasColumn('boards', 'notify_admin_on_post_channels')) {
                $table->text('notify_admin_on_post_channels')->nullable()->after('notify_author_channels')->comment('관리자 알림 채널 (배열, 예: ["mail", "database"])');
            }
        });
    }
};
