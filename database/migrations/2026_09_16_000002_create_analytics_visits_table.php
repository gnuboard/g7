<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 방문 원장 테이블을 생성합니다.
     *
     * 방문 1건 = 이 표의 1행이며, 같은 방문이 이어지는 동안 액션 수·체류 시간·이탈
     * 페이지가 계속 갱신되는 유일한 표입니다. user_id 에 외래키를 두지 않는 이유는
     * 회원이 탈퇴해도 과거 통계가 사라지거나 탈퇴가 막히면 안 되기 때문입니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_visits')) {
            return;
        }

        Schema::create('analytics_visits', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->char('visitor_key', 32)->comment('방문자 식별 키 (쿠키 값 또는 익명 모드의 일자별 해시)');
            $table->string('visitor_key_type', 10)->comment('키 출처 (cookie, config, server — server 는 쿠키 없는 서버 폴백의 임시 키)');
            $table->string('visitor_type', 10)->comment('방문자 유형 (guest, member, admin)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('회원 ID — 외래키를 두지 않는다 (탈퇴 후에도 통계를 남기기 위함)');
            $table->char('user_hash', 64)->nullable()->comment('회원 ID 가명화 해시 — 가명화 설정이 켜지면 user_id 대신 이 값만 남는다');
            $table->date('visit_date')->comment('방문 시작일 (사이트 표준시 기준)');
            $table->dateTime('first_action_at')->comment('첫 액션 시각 (UTC)');
            $table->dateTime('last_action_at')->comment('마지막 액션 시각 (UTC) — 방문 이어짐 판정 기준');
            $table->unsignedInteger('actions_count')->default(0)->comment('이 방문의 전체 액션 수');
            $table->unsignedInteger('pageviews_count')->default(0)->comment('이 방문의 페이지뷰 수');
            $table->unsignedInteger('total_time_seconds')->default(0)->comment('체류 시간 합계 (초) — 마지막 페이지는 0으로 친다');
            $table->boolean('is_bounce')->default(true)->comment('이탈 여부 (페이지를 하나만 보고 떠났는가)');
            $table->unsignedBigInteger('entry_action_name_id')->nullable()->comment('진입 페이지 이름 사전 ID');
            $table->unsignedBigInteger('exit_action_name_id')->nullable()->comment('이탈 페이지 이름 사전 ID');
            $table->string('source', 10)->comment('수집 경로 (beacon, server)');
            $table->binary('ip', 16)->nullable()->comment('방문자 IP 이진값 — 마스킹 설정을 적용한 뒤 저장, 익명 모드에서는 비운다');
            $table->char('ip_hash', 64)->nullable()->comment('IP 해시 — 익명 모드에서 IP 대신 저장 (원본 IP 복원 불가)');
            $table->char('country_code', 2)->nullable()->comment('국가 코드');
            $table->string('region_code', 10)->nullable()->comment('지역 코드');
            $table->string('city', 100)->nullable()->comment('도시명');
            $table->string('browser_name', 50)->comment('브라우저 이름');
            $table->string('browser_version', 20)->nullable()->comment('브라우저 버전');
            $table->string('os_name', 50)->comment('운영체제 이름');
            $table->string('os_version', 20)->nullable()->comment('운영체제 버전');
            $table->string('device_type', 10)->comment('기기 종류 (desktop, mobile, tablet, other)');
            $table->string('screen_resolution', 12)->nullable()->comment('화면 해상도 (가로x세로)');
            $table->string('browser_language', 10)->nullable()->comment('브라우저 언어');
            $table->string('locale', 10)->nullable()->comment('사이트에서 보고 있던 로케일');
            $table->string('referrer_type', 10)->comment('유입 종류 (direct, search, website, campaign)');
            $table->string('referrer_name', 100)->nullable()->comment('유입 이름 (검색엔진명 또는 사이트 호스트)');
            $table->string('referrer_url', 1024)->nullable()->comment('유입 URL — referrer 익명화 설정이 켜지면 도메인까지만 남는다');
            $table->string('referrer_keyword', 255)->nullable()->comment('검색 키워드 — 요즘 검색엔진은 대부분 넘겨주지 않는다');
            $table->string('campaign_name', 100)->nullable()->comment('캠페인 이름');
            $table->string('campaign_source', 100)->nullable()->comment('캠페인 유입처');
            $table->string('campaign_medium', 100)->nullable()->comment('캠페인 매체');
            $table->boolean('is_returning')->nullable()->comment('재방문 여부 — 쿠키를 쓰는 모드에서만 판정할 수 있다 (익명 모드는 비운다)');
            $table->json('custom_dimensions')->nullable()->comment('확장이 넣은 커스텀 차원 값');
            $table->timestamp('created_at')->nullable()->comment('생성일시');
            $table->timestamp('updated_at')->nullable()->comment('수정일시 — 방문이 이어지는 동안 액션마다 갱신된다');

            $table->index(['visitor_key', 'last_action_at'], 'idx_analytics_visits_visitor_last');
            $table->index(['visit_date', 'visitor_type'], 'idx_analytics_visits_date_type');
            $table->index('last_action_at', 'idx_analytics_visits_last_action');
            $table->index('user_id', 'idx_analytics_visits_user');
            $table->index('ip_hash', 'idx_analytics_visits_ip_hash');
        });

        // 이진 컬럼 인덱스는 Blueprint 로 길이를 지정할 수 없어 드라이버별로 나눈다.
        // MySQL 은 varbinary(16) 전체를 인덱싱해도 부담이 없다.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('analytics_visits', function (Blueprint $table) {
                $table->index('ip', 'idx_analytics_visits_ip');
            });
        }
    }

    /**
     * 방문자 통계 방문 원장 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_visits');
    }
};
