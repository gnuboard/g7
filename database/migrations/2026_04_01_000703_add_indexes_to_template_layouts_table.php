<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * template_layouts 테이블 인덱스 추가.
 *
 * - template_id: 템플릿별 레이아웃 조회
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = array_column(Schema::getIndexes('template_layouts'), 'name');

        if (! in_array('idx_template_layouts_template_id', $existingIndexes)) {
            Schema::table('template_layouts', function (Blueprint $table) {
                $table->index('template_id', 'idx_template_layouts_template_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('template_layouts')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('template_layouts'), 'name');

        if (in_array('idx_template_layouts_template_id', $existingIndexes)) {
            Schema::table('template_layouts', function (Blueprint $table) {
                $table->dropIndex('idx_template_layouts_template_id');
            });
        }
    }
};
