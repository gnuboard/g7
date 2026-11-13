<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 동적 위치 대상별 슬라이더 설정 재정의 테이블을 만듭니다. 대상이 명시한 항목만 overrides 에 담고(키 없음 = 위치 기본값 상속),
     * 기존 위치 설정 행은 건드리지 않는다 — 처음에는 비어 있어 모든 대상이 위치 기본값을 그대로 쓴다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('hero_section_target_settings')) {
            return;
        }

        Schema::create('hero_section_target_settings', function (Blueprint $table) {
            $table->id();
            $table->string('position', 50)->comment('위치 키 (동적 위치)');
            $table->string('target_key', 100)->comment('대상 키 (해석기가 준 정규 키 — 게시판·상품·카테고리 id 등)');
            $table->json('overrides')->comment('대상이 명시한 슬라이더 설정 항목만(키 없음 = 위치 기본값 상속, false·null 도 명시값)');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('마지막으로 바꾼 회원 ID');
            $table->timestamps();

            $table->unique(['position', 'target_key'], 'hero_target_settings_placement_unique');
        });
    }

    /**
     * 대상별 설정 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_section_target_settings');
    }
};
