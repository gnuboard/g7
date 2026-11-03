<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 히어로섹션 배너 테이블을 만듭니다. 노출 상태는 저장하지 않고 사용 여부·기간으로 조회 시점에 계산합니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('hero_section_banners')) {
            return;
        }

        Schema::create('hero_section_banners', function (Blueprint $table) {
            $table->id()->comment('배너 ID');
            $table->string('position', 50)->comment('노출 위치 키 (템플릿 확장점 이름: home_hero, shop_hero)');
            $table->boolean('is_active')->default(true)->comment('사용 여부: 0 비활성, 1 사용');
            $table->unsignedInteger('sort_order')->default(0)->comment('위치 안 노출 순서 (작을수록 앞)');
            $table->string('display_period', 10)->default('always')->comment('노출 기간 방식: always 상시, range 기간 지정');
            $table->dateTime('starts_at')->nullable()->comment('노출 시작 일시 (UTC, range 일 때)');
            $table->dateTime('ends_at')->nullable()->comment('노출 종료 일시 (UTC, range 일 때)');
            $table->text('title')->comment('제목 (다국어 JSON: {ko: "...", en: "..."})');
            $table->text('description')->nullable()->comment('설명 (다국어 JSON)');
            $table->text('button_text')->nullable()->comment('버튼 문구 (다국어 JSON, 비면 배너 전체가 링크)');
            $table->text('image_alt')->nullable()->comment('이미지 대체 텍스트 (다국어 JSON, 비면 제목으로 대체)');
            $table->boolean('title_visible')->default(true)->comment('제목 표시 여부');
            $table->string('title_color', 7)->default('#FFFFFF')->comment('제목 색상 (#RRGGBB)');
            $table->string('title_align', 10)->default('left')->comment('제목 정렬: left, center, right');
            $table->boolean('description_visible')->default(true)->comment('설명 표시 여부');
            $table->string('description_color', 7)->default('#E5E7EB')->comment('설명 색상 (#RRGGBB)');
            $table->string('description_align', 10)->default('left')->comment('설명 정렬: left, center, right');
            $table->string('link_url', 2048)->nullable()->comment('이동할 주소 (사이트 내부 경로 또는 http(s))');
            $table->boolean('link_new_window')->default(false)->comment('새 창에서 열기 여부');
            $table->unsignedBigInteger('created_by')->nullable()->comment('등록한 회원 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('마지막으로 수정한 회원 ID');
            $table->timestamps();

            $table->index(['position', 'sort_order'], 'hero_banners_position_order_idx');
            $table->index(['position', 'is_active', 'display_period', 'starts_at', 'ends_at'], 'hero_banners_exposure_idx');
        });
    }

    /**
     * 히어로섹션 배너 테이블을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_section_banners');
    }
};
