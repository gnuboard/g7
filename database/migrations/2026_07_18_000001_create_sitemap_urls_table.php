<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sitemap_urls 테이블을 생성합니다.
     *
     * 사이트맵 URL 을 리소스 단위로 영속화하여 진정한 증분 갱신(공개=upsert / 비공개=remove)과
     * 도메인 재쿼리 없는 파일 재작성을 가능하게 합니다. 리소스 변경 리스너가 이 테이블에
     * 델타를 반영하고, 파일 생성은 이 테이블을 스트리밍하여 수행합니다.
     */
    public function up(): void
    {
        if (Schema::hasTable('sitemap_urls')) {
            return;
        }

        Schema::create('sitemap_urls', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('사이트맵 URL 행 ID');
            $table->string('resource_type', 64)->index()->comment('리소스 유형 (page, board_post, product, category, board, board_index, shop_index 등)');
            $table->string('resource_id', 64)->nullable()->comment('리소스 PK (uuid/int 문자열). 인덱스/컬렉션 URL 은 null');
            $table->string('loc', 2048)->comment('절대 base URL (로케일 무관 — 렌더 시점에 로케일별 확장)');
            // loc 는 2048 자라 unique 인덱스에 직접 넣으면 InnoDB 키 길이(3072바이트) 를 초과하므로
            // sha256 해시(64자 hex)를 identity 로 사용합니다. ascii 컬럼이라 인덱스 비용이 작습니다.
            $table->char('loc_hash', 64)->charset('ascii')->comment('loc 의 sha256 해시 (upsert/delete identity)');
            $table->timestamp('lastmod')->nullable()->comment('최종 수정 시각 (<lastmod>)');
            $table->string('changefreq', 16)->nullable()->comment('변경 빈도 (<changefreq>)');
            $table->decimal('priority', 2, 1)->nullable()->comment('우선순위 0.0~1.0 (<priority>)');
            $table->string('contributor', 64)->comment('기여자 식별자 (getIdentifier — 샤드/리빌드 스코핑)');
            $table->boolean('is_visible')->default(true)->comment('공개 여부 (true 만 사이트맵에 노출)');
            $table->timestamps();

            // 리소스별 URL identity — upsert/delete 대상 식별
            $table->unique(['resource_type', 'resource_id', 'loc_hash'], 'sitemap_urls_identity_unique');
            // 리빌드/증분 스트리밍 커서 (contributor 스코핑 + 가시성 + id 순서)
            $table->index(['contributor', 'is_visible', 'id'], 'sitemap_urls_stream_index');
        });
    }

    /**
     * sitemap_urls 테이블을 제거합니다.
     */
    public function down(): void
    {
        if (Schema::hasTable('sitemap_urls')) {
            Schema::drop('sitemap_urls');
        }
    }
};
