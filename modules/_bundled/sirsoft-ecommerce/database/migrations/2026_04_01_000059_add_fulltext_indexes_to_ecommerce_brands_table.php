<?php

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_brands 테이블에 FULLTEXT 인덱스 추가
 *
 * name 컬럼에 대해 MATCH...AGAINST 검색을 지원합니다.
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
        DatabaseFulltextEngine::addFulltextIndex('ecommerce_brands', 'ft_ecommerce_brands_name', 'name');
    }

    /**
     * 마이그레이션을 롤백합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_brands')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('ecommerce_brands'), 'name');

        Schema::table('ecommerce_brands', function (Blueprint $table) use ($indexes) {
            if (in_array('ft_ecommerce_brands_name', $indexes)) {
                $table->dropIndex('ft_ecommerce_brands_name');
            }
        });
    }
};
