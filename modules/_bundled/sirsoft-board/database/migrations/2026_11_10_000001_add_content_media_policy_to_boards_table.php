<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boards 테이블에 본문 미디어 정책 컬럼을 추가합니다.
 *
 * 편집기 본문에 넣는 이미지·동영상·오디오의 게시판별 정책(종류별 허용·파일당 MB·확장자, 문서당 최대 개수)입니다.
 * null 이면 그 게시판의 첨부 정책(최대 파일 용량·허용 확장자)으로 만든 대체 정책을 따릅니다 — 동영상·오디오는 코어
 * 「본문 콘텐츠」 스위치를 따릅니다. 기존 게시판은 null 로 남아 업데이트 직후에도 본문 이미지 업로드가 첨부 정책대로 동작합니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('boards') || Schema::hasColumn('boards', 'content_media_policy')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->json('content_media_policy')
                ->nullable()
                ->comment('본문 미디어 정책 (null: 게시판 첨부 정책을 따름)')
                ->after('allowed_extensions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('boards') || ! Schema::hasColumn('boards', 'content_media_policy')) {
            return;
        }

        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('content_media_policy');
        });
    }
};
