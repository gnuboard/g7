<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * board_attachments 테이블에 비회원 업로드 소유 증명 컬럼을 추가합니다.
 *
 * 비회원이 올린 임시 첨부를 그 비회원이 지우고 순서를 바꿀 수 있게, 업로드 응답에서 준 쿠키 값의 SHA-256 을 둡니다.
 * 글에 연결되면 지웁니다(연결 전만 의미). 기존 행은 null — 종전처럼 비회원이 지울 수 없습니다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_attachments') || Schema::hasColumn('board_attachments', 'guest_owner_hash')) {
            return;
        }

        Schema::table('board_attachments', function (Blueprint $table) {
            $table->string('guest_owner_hash', 64)
                ->nullable()
                ->comment('비회원 업로드 소유 증명 쿠키의 SHA-256 (연결 전만 의미)')
                ->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('board_attachments') || ! Schema::hasColumn('board_attachments', 'guest_owner_hash')) {
            return;
        }

        Schema::table('board_attachments', function (Blueprint $table) {
            $table->dropColumn('guest_owner_hash');
        });
    }
};
