<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board_posts 테이블 인덱스 추가. (LIST 파티션 테이블)
 *
 * - [board_id, author_name]: 비회원 작성자 검색 (파티션 프루닝)
 * - [board_id, status, created_at]: 게시판별 상태+날짜 필터 (가장 빈번한 패턴)
 *
 * 참고: [board_id, status, created_at]는 기존 [board_id, status] 인덱스를 포함합니다.
 * 기존 인덱스 제거는 별도 후속 작업으로 처리합니다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            $table->index(['board_id', 'author_name'], 'idx_board_posts_board_author');
            $table->index(['board_id', 'status', 'created_at'], 'idx_board_posts_board_status_created');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('board_posts')) {
            return;
        }

        $existingIndexes = array_column(Schema::getIndexes('board_posts'), 'name');

        Schema::table('board_posts', function (Blueprint $table) use ($existingIndexes) {
            $indexes = [
                'idx_board_posts_board_author',
                'idx_board_posts_board_status_created',
            ];

            foreach ($indexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
