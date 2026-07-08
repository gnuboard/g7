<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'deleted_at')) {
            return;
        }

        // 잔존 soft-delete 페이지 행을 물리 삭제한다.
        // page_attachments.page_id 의 cascadeOnDelete FK 로 연관 첨부 행도 함께 제거된다.
        // (첨부 물리 파일은 페이지 삭제 시점에 이미 제거된 상태 — 여기서는 DB 행만 정리)
        DB::table('pages')->whereNotNull('deleted_at')->delete();

        Schema::table('pages', function (Blueprint $table) {
            // 소프트 삭제 컬럼 제거 (페이지는 물리 삭제 방식으로 전환)
            $table->dropSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('pages') || Schema::hasColumn('pages', 'deleted_at')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            // 소프트 삭제 컬럼 복원
            $table->softDeletes();
        });
    }
};
