<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 팝업 테이블을 만듭니다. 노출 상태는 컬럼이 아니라 조회 시점에 사용 여부·기간으로 계산합니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('popups')) {
            return;
        }

        Schema::create('popups', function (Blueprint $table) {
            $table->id()->comment('팝업 ID');
            $table->string('title', 100)->comment('관리용 제목 (관리자 목록에만 보임, 다국어 아님)');
            $table->boolean('is_active')->default(true)->comment('사용 여부: true(노출 — 기간·대상 조건에 맞으면 보임), false(비노출)');
            $table->unsignedInteger('sort_order')->default(0)->comment('캐러셀 순서 (작을수록 앞)');
            $table->string('content_type', 10)->default('image')->comment('내용 유형: image(이미지형), html(직접 작성형)');
            $table->string('display_period', 10)->default('always')->comment('노출 기간: always(상시), range(기간 지정)');
            $table->dateTime('starts_at')->nullable()->comment('노출 시작 일시 (UTC, 기간 지정일 때)');
            $table->dateTime('ends_at')->nullable()->comment('노출 종료 일시 (UTC, 기간 지정일 때)');
            $table->string('page_scope', 10)->default('all')->comment('노출 페이지: all(전체), routes(라우트 지정)');
            $table->json('routes')->nullable()->comment('정규화된 노출 경로 목록 (라우트 지정일 때, 경로 경계 하위 포함)');
            $table->string('audience', 10)->default('all')->comment('노출 대상: all(전체), guest(비회원만), member(회원만)');
            $table->string('device', 10)->default('all')->comment('노출 기기: all(전체), pc, mobile');
            $table->text('image_alt')->nullable()->comment('이미지 대체 텍스트 (다국어 JSON)');
            $table->string('link_url', 2048)->nullable()->comment('이미지를 누르면 이동할 주소 (내부 경로 또는 http(s))');
            $table->boolean('link_new_window')->default(false)->comment('링크 열기 방식: true(새 창), false(현재 창)');
            $table->longText('body_html')->nullable()->comment('직접 작성형 본문 (다국어 JSON, 저장 때 정화한 HTML)');
            $table->unsignedInteger('lock_version')->default(0)->comment('동시 수정 충돌 확인용 세대 번호');
            $table->unsignedBigInteger('created_by')->nullable()->comment('등록한 회원 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('마지막으로 수정한 회원 ID');
            $table->timestamps();

            $table->index(['sort_order', 'id'], 'popups_sort_idx');
            $table->index(['is_active', 'display_period', 'starts_at', 'ends_at'], 'popups_exposure_idx');
        });
    }

    /**
     * 팝업 테이블을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('popups');
    }
};
