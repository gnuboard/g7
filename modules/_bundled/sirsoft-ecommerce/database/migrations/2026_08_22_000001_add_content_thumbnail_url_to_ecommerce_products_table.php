<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ecommerce_products 에 설명 첫 내부 이미지 URL 캐시 컬럼 추가 (공개 이슈 #22 동종)
     *
     * 상품 이미지 없이 상세설명(에디터)에만 이미지를 넣은 상품은 목록·og:image 6개
     * 표면이 동시에 비어 있었다. 저장 시점(모델 saving 이벤트)에 추출해 캐시하고,
     * Product::getThumbnailUrl() 이 상품 이미지 부재 시 이 값으로 폴백한다.
     * 기존 행 백필은 Upgrade_1_2_0 업그레이드 스텝이 수행한다.
     */
    public function up(): void
    {
        Schema::table('ecommerce_products', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_products', 'content_thumbnail_url')) {
                $table->string('content_thumbnail_url', 1000)
                    ->nullable()
                    ->after('description_mode')
                    ->comment('설명 첫 내부 이미지 URL 캐시 — 상품 이미지가 없을 때 목록/공유 썸네일 폴백');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_products')) {
            $columns = Schema::getColumnListing('ecommerce_products');

            Schema::table('ecommerce_products', function (Blueprint $table) use ($columns) {
                if (in_array('content_thumbnail_url', $columns)) {
                    $table->dropColumn('content_thumbnail_url');
                }
            });
        }
    }
};
