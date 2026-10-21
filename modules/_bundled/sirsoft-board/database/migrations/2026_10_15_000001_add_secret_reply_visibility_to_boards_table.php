<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boards 테이블에 비밀 답변 열람 범위 컬럼을 추가합니다.
 *
 * 비밀글 스레드에서 원글·답변 작성자와 비밀번호 인증자가 서로의 글을 어디까지 보는지 게시판별로 정합니다.
 *  - 'thread_path' (기본값): 열 수 있는 글의 하위 전체 + 자기 글의 조상 경로
 *  - 'descendants_only': 열 수 있는 글의 하위만
 *  - 'per_post': 답변마다 따로 (작성자·비밀번호 인증자·권한자만)
 *
 * NOT NULL + default 라 ALTER 시 기존 행이 자동으로 'thread_path' 로 채워집니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('boards') || Schema::hasColumn('boards', 'secret_reply_visibility')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->string('secret_reply_visibility', 20)
                ->default('thread_path')
                ->comment('비밀 답변 열람 범위 (thread_path: 경로 규칙, descendants_only: 하위만, per_post: 답변마다 따로)')
                ->after('reply_delete_policy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('boards') || ! Schema::hasColumn('boards', 'secret_reply_visibility')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('secret_reply_visibility');
        });
    }
};
