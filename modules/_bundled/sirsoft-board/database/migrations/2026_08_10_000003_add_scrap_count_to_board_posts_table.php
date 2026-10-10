<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 게시글 스크랩 수 누적 컬럼 추가.
     * comments_count 등과 동일하게 매 집계 대신 증감 컬럼으로 성능을 확보한다.
     */
    public function up(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('board_posts', 'scrap_count')) {
                $table->unsignedInteger('scrap_count')
                    ->default(0)
                    ->after('attachments_count')
                    ->comment('스크랩 수');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_posts') && Schema::hasColumn('board_posts', 'scrap_count')) {
            Schema::table('board_posts', function (Blueprint $table) {
                $table->dropColumn('scrap_count');
            });
        }
    }
};
