<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ecommerce_products', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_products', 'seo_sync_title')) {
                // SEO 제목 동기화 여부 — ON 이면 저장 시 서버가 상품명(name)으로 meta_title 을 채운다.
                // OFF 이면 사용자 입력 meta_title 을 그대로 보존한다. 사용자 의도를 서버에 영속하여
                // meta_title 유무 역추론으로 커스텀 입력이 덮어써지던 회귀를 차단한다.
                $table->boolean('seo_sync_title')
                    ->default(true)
                    ->after('meta_title')
                    ->comment('SEO 제목 동기화 여부 (1: 상품명으로 자동 채움, 0: 직접 입력 보존)');
            }

            if (! Schema::hasColumn('ecommerce_products', 'seo_sync_description')) {
                // SEO 설명 동기화 여부 — ON 이면 저장 시 서버가 상품 설명(description)으로 meta_description 을 채운다.
                $table->boolean('seo_sync_description')
                    ->default(true)
                    ->after('meta_description')
                    ->comment('SEO 설명 동기화 여부 (1: 상품 설명으로 자동 채움, 0: 직접 입력 보존)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_products', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_products', 'seo_sync_description')) {
                $table->dropColumn('seo_sync_description');
            }
            if (Schema::hasColumn('ecommerce_products', 'seo_sync_title')) {
                $table->dropColumn('seo_sync_title');
            }
        });
    }
};
