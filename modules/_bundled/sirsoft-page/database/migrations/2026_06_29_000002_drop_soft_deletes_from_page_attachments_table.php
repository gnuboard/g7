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
        if (! Schema::hasTable('page_attachments') || ! Schema::hasColumn('page_attachments', 'deleted_at')) {
            return;
        }

        // 잔존 soft-delete 첨부 행을 물리 삭제한다.
        // (물리 파일은 첨부 삭제 시점에 이미 제거된 상태 — 여기서는 DB 행만 정리)
        DB::table('page_attachments')->whereNotNull('deleted_at')->delete();

        Schema::table('page_attachments', function (Blueprint $table) {
            // deleted_at 인덱스 제거 (소프트 삭제 미사용으로 불필요)
            $table->dropIndex(['deleted_at']);
            // 소프트 삭제 컬럼 제거 (첨부는 물리 삭제 방식으로 전환)
            $table->dropSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('page_attachments') || Schema::hasColumn('page_attachments', 'deleted_at')) {
            return;
        }

        Schema::table('page_attachments', function (Blueprint $table) {
            // 소프트 삭제 컬럼 복원
            $table->softDeletes()->comment('소프트 삭제 일시');
            // deleted_at 인덱스 복원
            $table->index('deleted_at');
        });
    }
};
