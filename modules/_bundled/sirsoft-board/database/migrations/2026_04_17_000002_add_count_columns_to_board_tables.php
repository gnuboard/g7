<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 게시글/댓글 테이블에 카운팅 컬럼 추가
     *
     * 기존 데이터 동기화는 Upgrade_1_0_0_beta_2 업그레이드 스텝에서 chunk 처리로 수행
     */
    public function up(): void
    {
        // board_posts: replies_count, comments_count, attachments_count
        Schema::table('board_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('board_posts', 'replies_count')) {
                $table->unsignedInteger('replies_count')
                    ->default(0)
                    ->after('view_count')
                    ->comment('답글 수');
            }

            if (! Schema::hasColumn('board_posts', 'comments_count')) {
                $table->unsignedInteger('comments_count')
                    ->default(0)
                    ->after('replies_count')
                    ->comment('댓글 수');
            }

            if (! Schema::hasColumn('board_posts', 'attachments_count')) {
                $table->unsignedInteger('attachments_count')
                    ->default(0)
                    ->after('comments_count')
                    ->comment('첨부파일 수');
            }
        });

        // board_comments: replies_count
        Schema::table('board_comments', function (Blueprint $table) {
            if (! Schema::hasColumn('board_comments', 'replies_count')) {
                $table->unsignedInteger('replies_count')
                    ->default(0)
                    ->after('status')
                    ->comment('대댓글 수');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('board_posts')) {
            $columns = Schema::getColumnListing('board_posts');

            Schema::table('board_posts', function (Blueprint $table) use ($columns) {
                if (in_array('replies_count', $columns)) {
                    $table->dropColumn('replies_count');
                }
                if (in_array('comments_count', $columns)) {
                    $table->dropColumn('comments_count');
                }
                if (in_array('attachments_count', $columns)) {
                    $table->dropColumn('attachments_count');
                }
            });
        }

        if (Schema::hasTable('board_comments')) {
            $columns = Schema::getColumnListing('board_comments');

            Schema::table('board_comments', function (Blueprint $table) use ($columns) {
                if (in_array('replies_count', $columns)) {
                    $table->dropColumn('replies_count');
                }
            });
        }
    }
};
