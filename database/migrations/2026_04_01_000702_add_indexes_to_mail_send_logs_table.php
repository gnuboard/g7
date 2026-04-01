<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mail_send_logs 테이블 인덱스 추가.
 *
 * - status: sent/failed 상태 필터
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('mail_send_logs'), 'name');

        if (! in_array('idx_mail_send_logs_status', $existingIndexes)) {
            Schema::table('mail_send_logs', function (Blueprint $table) {
                $table->index('status', 'idx_mail_send_logs_status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('mail_send_logs')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('mail_send_logs'), 'name');

        if (in_array('idx_mail_send_logs_status', $existingIndexes)) {
            Schema::table('mail_send_logs', function (Blueprint $table) {
                $table->dropIndex('idx_mail_send_logs_status');
            });
        }
    }
};
