<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 이름 사전 테이블을 생성합니다.
     *
     * 같은 URL·제목·파일명·검색어가 수천 번 반복되므로 원장에 문자열을 그대로 쌓지 않고
     * 이 사전에 한 번만 적은 뒤 액션은 id 로 가리킵니다. 정리 배치는 이 표를 지우지
     * 않습니다 — 집계 행이 action_name_id 로 라벨을 찾기 때문입니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_action_names')) {
            return;
        }

        Schema::create('analytics_action_names', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('type', 12)->comment('이름 종류 (url, title, download, outlink, search)');
            $table->string('name', 1024)->comment('정규화된 이름 (URL 은 스킴·www 접두를 뗀 나머지)');
            $table->char('name_hash', 32)->comment('종류와 이름으로 만든 해시 — 긴 문자열 대신 이 값으로 중복을 막는다');
            $table->unsignedTinyInteger('url_prefix')->nullable()->comment('URL 접두 (0 http, 1 http+www, 2 https, 3 https+www) — URL 종류에만 쓴다');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->unique(['type', 'name_hash'], 'uq_analytics_action_names_hash');
        });
    }

    /**
     * 방문자 통계 이름 사전 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_action_names');
    }
};
