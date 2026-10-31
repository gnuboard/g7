<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boards 테이블에 활성 반응 유형 목록 컬럼 추가
     *
     * 게시판별로 켠 반응 유형의 code(문자열) 목록을 JSON 배열로 저장한다.
     * 예: ["like","dislike"]. ID가 아닌 code로 저장하는 이유는 시더 실행 순서에
     * 따라 ID가 환경마다 달라질 수 있어서다 (설정값은 사람이 읽는 code로 통일).
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (! Schema::hasColumn('boards', 'active_reaction_types')) {
                $table->text('active_reaction_types')
                    ->nullable()
                    ->after('use_reaction')
                    ->comment('활성화된 반응 유형 code 목록 JSON 배열 (예: ["like","dislike"])');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('boards')) {
            $columns = Schema::getColumnListing('boards');

            Schema::table('boards', function (Blueprint $table) use ($columns) {
                if (in_array('active_reaction_types', $columns)) {
                    $table->dropColumn('active_reaction_types');
                }
            });
        }
    }
};
