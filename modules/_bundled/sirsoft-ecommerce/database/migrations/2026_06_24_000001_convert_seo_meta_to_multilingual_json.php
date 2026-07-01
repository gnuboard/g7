<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 상품·카테고리의 SEO 제목/설명(meta_title/meta_description)을 다국어 JSON 보관용으로 전환합니다.
     *
     * - meta_title: string(200) → text (다국어 JSON {ko:"...", en:"..."} 저장용으로 확장)
     * - meta_description: 이미 text — 타입 유지, comment 만 다국어 의미로 갱신
     *
     * 스키마 타입 변경만 담당하며, 기존 평문 row → JSON 변환(백필)은 upgrade step
     * (upgrades/data/1.0.0-beta.5/migrations/ConvertSeoMetaPlainToJson) 에서 수행한다.
     * name/description 컬럼과 동일하게 모델 AsUnicodeJson 캐스팅으로 언어별 SEO 메타 분기를 지원한다.
     */
    public function up(): void
    {
        foreach (['ecommerce_products', 'ecommerce_categories'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // meta_title: string(200) → text (다국어 JSON 보관용으로 확장)
                if (Schema::hasColumn($tableName, 'meta_title')) {
                    $table->text('meta_title')->nullable()->comment('SEO 제목 (다국어 JSON)')->change();
                }

                // meta_description: 이미 text — comment 만 다국어 의미로 갱신
                if (Schema::hasColumn($tableName, 'meta_description')) {
                    $table->text('meta_description')->nullable()->comment('SEO 설명 (다국어 JSON)')->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * meta_title 을 string(200) 으로 환원합니다. (다국어 JSON → 평문 데이터 복구는
     * 운영자가 down 전 데이터 정리를 전제로 한다.)
     */
    public function down(): void
    {
        foreach (['ecommerce_products', 'ecommerce_categories'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'meta_title')) {
                    $table->string('meta_title', 200)->nullable()->comment('SEO 제목')->change();
                }
                if (Schema::hasColumn($tableName, 'meta_description')) {
                    $table->text('meta_description')->nullable()->comment('SEO 설명')->change();
                }
            });
        }
    }
};
