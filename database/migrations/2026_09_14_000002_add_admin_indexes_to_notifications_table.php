<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 관리자 회원 알림함 목록용 인덱스를 추가한다.
     *
     * `notifications` 는 Laravel 표준 테이블이라 인덱스가 `(notifiable_type, notifiable_id)`
     * 하나뿐이다. 관리자 알림함은 회원을 특정하지 않고 `created_at` 으로 정렬하므로 그대로 두면
     * 전체 스캔 + filesort 가 되고, 지연 조인의 inner 단계가 기대는 정렬 인덱스도 없다.
     *
     * - idx_notifications_created_at (created_at): 전체 목록의 기본 정렬
     * - idx_notifications_notifiable_created (notifiable_type, notifiable_id, created_at):
     *   회원 한정 + 정렬 조합 (회원 상세에서 넘어오는 경로)
     *
     * 기설치본에도 그대로 적용되도록 hasIndex 가드를 둔다 (재실행 안전).
     */
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasIndex('notifications', 'idx_notifications_created_at')) {
                $table->index('created_at', 'idx_notifications_created_at');
            }

            if (! Schema::hasIndex('notifications', 'idx_notifications_notifiable_created')) {
                $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'idx_notifications_notifiable_created');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasIndex('notifications', 'idx_notifications_created_at')) {
                $table->dropIndex('idx_notifications_created_at');
            }

            if (Schema::hasIndex('notifications', 'idx_notifications_notifiable_created')) {
                $table->dropIndex('idx_notifications_notifiable_created');
            }
        });
    }
};
