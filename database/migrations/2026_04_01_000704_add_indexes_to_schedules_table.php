<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schedules 테이블 인덱스 추가.
 *
 * - created_at: 날짜 범위 필터, 정렬
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('schedules'), 'name');

        if (! in_array('idx_schedules_created_at', $existingIndexes)) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->index('created_at', 'idx_schedules_created_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedules')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('schedules'), 'name');

        if (in_array('idx_schedules_created_at', $existingIndexes)) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_created_at');
            });
        }
    }
};
