<?php

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_product_common_infos 테이블에 FULLTEXT 인덱스 추가
 *
 * name, content 컬럼에 대해 MATCH...AGAINST 검색을 지원합니다.
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
        DatabaseFulltextEngine::addFulltextIndex('ecommerce_product_common_infos', 'ft_ecommerce_product_common_infos_name', 'name');
        DatabaseFulltextEngine::addFulltextIndex('ecommerce_product_common_infos', 'ft_ecommerce_product_common_infos_content', 'content');
    }

    /**
     * 마이그레이션을 롤백합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_product_common_infos')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('ecommerce_product_common_infos'), 'name');

        Schema::table('ecommerce_product_common_infos', function (Blueprint $table) use ($indexes) {
            if (in_array('ft_ecommerce_product_common_infos_name', $indexes)) {
                $table->dropIndex('ft_ecommerce_product_common_infos_name');
            }
            if (in_array('ft_ecommerce_product_common_infos_content', $indexes)) {
                $table->dropIndex('ft_ecommerce_product_common_infos_content');
            }
        });
    }
};
