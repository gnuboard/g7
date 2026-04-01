<?php

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boards_report_logs 테이블에 FULLTEXT 인덱스 추가
 *
 * snapshot 컬럼에 대해 MATCH...AGAINST 검색을 지원합니다.
 * FULLTEXT 미지원 DBMS에서는 자동 스킵됩니다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     *
     * @return void
     */
    public function up(): void
    {
        DatabaseFulltextEngine::addFulltextIndex('boards_report_logs', 'ft_boards_report_logs_snapshot', 'snapshot');
    }

    /**
     * 마이그레이션을 롤백합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('boards_report_logs')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('boards_report_logs'), 'name');

        Schema::table('boards_report_logs', function (Blueprint $table) use ($indexes) {
            if (in_array('ft_boards_report_logs_snapshot', $indexes)) {
                $table->dropIndex('ft_boards_report_logs_snapshot');
            }
        });
    }
};
