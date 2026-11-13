<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boards 테이블에 작성자 표시 이름 설정과 비회원 작성자 항목 설정 컬럼을 추가합니다.
 *
 * - display_name_setting: 이 게시판에서 회원 작성자를 어떤 이름으로 보일지. null 이면 환경설정 > 회원 > 표시 이름의
 *   「게시판」 영역 설정을 따릅니다.
 * - guest_author_fields: 비회원이 글과 댓글을 쓸 때 받을 항목(이메일 등)의 사용, 필수 여부. null 이면 게시판 환경설정의
 *   기본값을 따릅니다.
 *
 * 기존 게시판은 둘 다 null 로 남아 업데이트 직후에도 화면과 검증이 달라지지 않습니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('boards')) {
            return;
        }

        if (! Schema::hasColumn('boards', 'display_name_setting')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->json('display_name_setting')
                    ->nullable()
                    ->comment('작성자 표시 이름 설정 (null: 회원 설정의 게시판 영역을 따름)');
            });
        }

        if (! Schema::hasColumn('boards', 'guest_author_fields')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->json('guest_author_fields')
                    ->nullable()
                    ->comment('비회원 작성자 항목의 사용, 필수 설정 (null: 게시판 환경설정 기본값을 따름)');
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

        foreach (['display_name_setting', 'guest_author_fields'] as $column) {
            if (Schema::hasColumn('boards', $column)) {
                Schema::table('boards', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
