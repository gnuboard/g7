<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activity_logs 테이블 인덱스 추가.
 *
 * - description_key: WhereIn 기반 다국어 검색
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('activity_logs'), 'name');

        if (! in_array('idx_activity_logs_description_key', $existingIndexes)) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('description_key', 'idx_activity_logs_description_key');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('activity_logs'), 'name');

        if (in_array('idx_activity_logs_description_key', $existingIndexes)) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropIndex('idx_activity_logs_description_key');
            });
        }
    }
};
