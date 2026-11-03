<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 위치별 슬라이더 설정 테이블을 만듭니다. 행이 없으면 기본값으로 응답하고 첫 저장 때 만듭니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('hero_section_position_settings')) {
            return;
        }

        Schema::create('hero_section_position_settings', function (Blueprint $table) {
            $table->id()->comment('설정 ID');
            $table->string('position', 50)->unique()->comment('노출 위치 키');
            $table->boolean('autoplay')->default(true)->comment('자동 재생 여부');
            $table->unsignedTinyInteger('interval_seconds')->default(5)->comment('전환 간격 (초: 3, 5, 7)');
            $table->string('effect', 10)->default('slide')->comment('전환 효과: slide, fade, none');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('마지막으로 바꾼 회원 ID');
            $table->timestamps();
        });
    }

    /**
     * 위치별 슬라이더 설정 테이블을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_section_position_settings');
    }
};
