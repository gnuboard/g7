<?php

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pages 테이블에 FULLTEXT 인덱스 추가
 *
 * title, content 컬럼에 대해 MATCH...AGAINST 검색을 지원합니다.
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
        DatabaseFulltextEngine::addFulltextIndex('pages', 'ft_pages_title', 'title');
        DatabaseFulltextEngine::addFulltextIndex('pages', 'ft_pages_content', 'content');
    }

    /**
     * 마이그레이션을 롤백합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('pages'), 'name');

        Schema::table('pages', function (Blueprint $table) use ($indexes) {
            if (in_array('ft_pages_title', $indexes)) {
                $table->dropIndex('ft_pages_title');
            }
            if (in_array('ft_pages_content', $indexes)) {
                $table->dropIndex('ft_pages_content');
            }
        });
    }
};
