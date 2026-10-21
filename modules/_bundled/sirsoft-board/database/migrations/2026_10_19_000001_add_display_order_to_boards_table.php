<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boards 테이블에 표시 순서 컬럼을 추가합니다.
 *
 * 관리자가 정한 순서대로 사용자 메뉴·게시판 목록 화면·관리자 게시판 목록·통합검색 게시판 선택칸에 보입니다.
 * 정렬은 `display_order ASC, created_at ASC, id ASC` 라, 순서를 한 번도 정하지 않은 사이트(모든 행 0)는
 * 지금 메뉴 순서(오래된 순)와 같습니다 — 그래서 기존 행을 채우는 데이터 이관은 하지 않습니다.
 *
 * NOT NULL + default 0 이라 ALTER 시 기존 행이 자동으로 0 으로 채워집니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('boards') || Schema::hasColumn('boards', 'display_order')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->unsignedInteger('display_order')
                ->default(0)
                ->comment('사용자 메뉴·게시판 목록 표시 순서 (작을수록 앞, 0 은 미지정 — 생성 순으로 뒤따름)')
                ->after('is_active');
        });

        if (! Schema::hasIndex('boards', 'idx_boards_active_display_order')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->index(['is_active', 'display_order'], 'idx_boards_active_display_order');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('boards')) {
            return;
        }

        if (Schema::hasIndex('boards', 'idx_boards_active_display_order')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->dropIndex('idx_boards_active_display_order');
            });
        }

        if (Schema::hasColumn('boards', 'display_order')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->dropColumn('display_order');
            });
        }
    }
};
