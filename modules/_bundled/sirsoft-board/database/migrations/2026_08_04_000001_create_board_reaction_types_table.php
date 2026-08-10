<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 게시판 반응 유형 테이블 생성
     *
     * 반응(추천/비추천 등) 유형을 DB로 관리한다 (Enum 코드 고정 아님).
     * 향후 커뮤니티별 커스텀 명칭·관리자 CRUD 확장을 위한 구조 확보.
     */
    public function up(): void
    {
        Schema::create('board_reaction_types', function (Blueprint $table) {
            $table->id()->comment('반응 유형 ID');
            $table->string('code', 50)->unique()->comment('내부 식별자 (예: like, dislike)');
            $table->text('name')->comment('다국어 라벨 JSON ({"ko":"추천","en":"Recommend","ja":"おすすめ"})');
            $table->string('icon')->nullable()->comment('Font Awesome 아이콘 클래스 (예: fas fa-thumbs-up)');
            $table->unsignedInteger('display_order')->default(0)->comment('표시 순서');
            $table->boolean('is_active')->default(true)->comment('활성 여부 (완전 삭제 미지원, 비활성화만 가능)');
            $table->text('user_overrides')->nullable()->comment('사용자 수정 보존 필드 (언어팩 시드 머지 시 사용자 편집값 유지)');
            $table->timestamps();

            $table->index('is_active', 'idx_reaction_type_active');
            $table->index('display_order', 'idx_reaction_type_order');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('board_reaction_types', function (Blueprint $table) {
                $table->comment('게시판 반응 유형 (추천/비추천 등)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_reaction_types');
    }
};
