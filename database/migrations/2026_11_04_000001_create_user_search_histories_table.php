<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회원 최근 검색어 테이블을 만듭니다.
 *
 * - 검색창 제출 때 엔진이 기록한다(주소에 검색어를 직접 넣은 검색은 기록하지 않는다). 비회원은 브라우저에 둔다.
 * - scope: 검색 범위 id (`core.site` 통합검색 · `{확장 식별자}.{이름}` 확장 선언). 같은 범위끼리 목록을 공유한다.
 * - keyword: 앞뒤 공백을 떼고 연속 공백을 하나로 줄인 검색어. 같은 회원·범위·검색어는 한 행이고 다시 검색하면
 *   searched_at 만 새로 쓴다(유니크 키 — 대소문자 구분 없는 콜레이션이라 대소문자만 다른 검색어도 한 행이며,
 *   보이는 글자는 마지막으로 검색한 모양을 따른다).
 * - 범위별 보관 개수는 기록 때 서비스가 넘치는 옛 행을 지우고, 보관 기한은 개인정보 보유기간 작업이 지운다.
 * - 탈퇴·삭제 시 서비스가 명시 삭제한다. FK CASCADE 는 삭제 누락에 대비한 안전망일 뿐 의존하지 않는다.
 *
 * 인덱스
 * - (user_id, scope, keyword) 유니크: 중복 없이 갱신.
 * - (user_id, scope, searched_at): 회원·범위별 최신순 목록과 보관 개수 정리.
 * - (scope, searched_at): 범위별 보관 기한 파기.
 *
 * @since 7.1.5
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (Schema::hasTable('user_search_histories')) {
            return;
        }

        Schema::create('user_search_histories', function (Blueprint $table) {
            $table->comment('회원 최근 검색어 (검색 범위별, 보관 개수·보관 기한은 개인정보 설정)');

            $table->id()->comment('검색어 ID');
            $table->foreignId('user_id')
                ->comment('회원 users.id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('scope', 100)->comment('검색 범위 id (core.site 또는 {확장 식별자}.{이름})');
            $table->string('keyword', 100)->comment('검색어 (앞뒤 공백 제거·연속 공백 하나로)');
            $table->timestamp('searched_at')->comment('마지막으로 검색한 일시');

            $table->unique(['user_id', 'scope', 'keyword'], 'user_search_histories_user_scope_keyword_unique');
            $table->index(['user_id', 'scope', 'searched_at'], 'user_search_histories_user_scope_searched_index');
            $table->index(['scope', 'searched_at'], 'user_search_histories_scope_searched_index');
        });
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        Schema::dropIfExists('user_search_histories');
    }
};
