<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * board_posts 테이블에 반응 카운트 통합 컬럼 추가
     *
     * 유형별 반응 개수를 JSON 하나로 통합 저장한다. 키는 유형 ID 문자열.
     * 예: {"1":18,"2":2}. 라벨/code가 바뀌어도 카운트가 끊기지 않도록 ID를 키로 쓴다.
     * 2026_04_17_000002 패턴(hasColumn 가드 + after + 한국어 comment + down) 재사용.
     */
    public function up(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('board_posts', 'reaction_counts')) {
                $table->text('reaction_counts')
                    ->nullable()
                    ->after('attachments_count')
                    ->comment('반응 유형별 개수 JSON (키는 유형 ID, 예: {"1":18,"2":2})');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_posts')) {
            $columns = Schema::getColumnListing('board_posts');

            Schema::table('board_posts', function (Blueprint $table) use ($columns) {
                if (in_array('reaction_counts', $columns)) {
                    $table->dropColumn('reaction_counts');
                }
            });
        }
    }
};
