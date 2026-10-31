<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * board_comments 테이블에 반응 카운트 통합 컬럼 추가
     *
     * 댓글 반응(target_type=comment) 확장을 위해 게시글과 같은 캐시 구조를 댓글에도 둔다.
     * 유형별 반응 개수를 JSON 하나로 통합 저장하며 키는 유형 ID 문자열이다. 예: {"1":3,"2":1}.
     * 라벨/code 가 바뀌어도 카운트가 끊기지 않도록 ID 를 키로 쓴다(게시글 컬럼과 동일 규약).
     *
     * 2026_08_04_000005(board_posts.reaction_counts) 패턴을 그대로 따른다.
     */
    public function up(): void
    {
        Schema::table('board_comments', function (Blueprint $table) {
            if (! Schema::hasColumn('board_comments', 'reaction_counts')) {
                $table->text('reaction_counts')
                    ->nullable()
                    ->after('replies_count')
                    ->comment('반응 유형별 개수 JSON (키는 유형 ID, 예: {"1":3,"2":1})');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_comments')) {
            $columns = Schema::getColumnListing('board_comments');

            Schema::table('board_comments', function (Blueprint $table) use ($columns) {
                if (in_array('reaction_counts', $columns)) {
                    $table->dropColumn('reaction_counts');
                }
            });
        }
    }
};
