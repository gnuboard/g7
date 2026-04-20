<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * boards 테이블에 카운팅 컬럼 추가 (posts_count, comments_count)
     *
     * 기존 데이터 동기화는 Upgrade_1_0_0_beta_2 업그레이드 스텝에서 수행
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            if (! Schema::hasColumn('boards', 'posts_count')) {
                $table->unsignedInteger('posts_count')
                    ->default(0)
                    ->after('is_active')
                    ->comment('게시글 수');
            }

            if (! Schema::hasColumn('boards', 'comments_count')) {
                $table->unsignedInteger('comments_count')
                    ->default(0)
                    ->after('posts_count')
                    ->comment('댓글 수');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('boards')) {
            $columns = Schema::getColumnListing('boards');

            Schema::table('boards', function (Blueprint $table) use ($columns) {
                if (in_array('posts_count', $columns)) {
                    $table->dropColumn('posts_count');
                }
                if (in_array('comments_count', $columns)) {
                    $table->dropColumn('comments_count');
                }
            });
        }
    }
};
