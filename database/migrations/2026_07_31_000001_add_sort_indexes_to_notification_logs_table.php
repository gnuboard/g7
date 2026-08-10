<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 발송 이력 목록의 정렬 컬럼(수신자명·제목)에 인덱스를 추가합니다.
 *
 * 목록은 지연 조인(PaginatesWithDeferredJoin)으로 페이지네이션되는데, 그 전제는
 * "정렬은 인덱스가 있는 닫힌 집합" 이다. 인덱스가 없으면 inner 쿼리도 전체 스캔이 되어
 * 깊은 OFFSET 개선 폭이 사라진다. 화면 정렬 셀렉트가 수신자명순·제목순을 제공하므로
 * 두 컬럼에 인덱스를 둔다.
 *
 * - recipient_name: 수신자명순 정렬
 * - subject: 제목순 정렬
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('notification_logs'), 'name');

        Schema::table('notification_logs', function (Blueprint $table) use ($existingIndexes) {
            if (! in_array('idx_notification_logs_recipient_name', $existingIndexes)) {
                $table->index('recipient_name', 'idx_notification_logs_recipient_name');
            }

            if (! in_array('idx_notification_logs_subject', $existingIndexes)) {
                $table->index('subject', 'idx_notification_logs_subject');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('notification_logs'), 'name');

        Schema::table('notification_logs', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'idx_notification_logs_recipient_name',
                'idx_notification_logs_subject',
            ];

            foreach ($indexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
