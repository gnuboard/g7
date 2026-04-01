<?php

use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ecommerce_products 테이블에 FULLTEXT 인덱스 추가
 *
 * name, description 컬럼에 대해 MATCH...AGAINST 검색을 지원합니다.
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
        DatabaseFulltextEngine::addFulltextIndex('ecommerce_products', 'ft_ecommerce_products_name', 'name');
        DatabaseFulltextEngine::addFulltextIndex('ecommerce_products', 'ft_ecommerce_products_description', 'description');
    }

    /**
     * 마이그레이션을 롤백합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_products')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('ecommerce_products'), 'name');

        Schema::table('ecommerce_products', function (Blueprint $table) use ($indexes) {
            if (in_array('ft_ecommerce_products_name', $indexes)) {
                $table->dropIndex('ft_ecommerce_products_name');
            }
            if (in_array('ft_ecommerce_products_description', $indexes)) {
                $table->dropIndex('ft_ecommerce_products_description');
            }
        });
    }
};
