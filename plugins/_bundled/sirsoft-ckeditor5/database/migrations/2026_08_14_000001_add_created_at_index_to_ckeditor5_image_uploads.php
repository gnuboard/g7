<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션 실행
     *
     * 미참조 이미지 정리는 "보존기간이 지난 오래된 업로드" 를 created_at 오름차순으로
     * 훑으므로 해당 컬럼에 인덱스를 둔다. 관리 화면의 기본 정렬(최신순)도 같은 인덱스를 쓴다.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ckeditor5_image_uploads')) {
            return;
        }

        Schema::table('ckeditor5_image_uploads', function (Blueprint $table) {
            $table->index('created_at', 'ckeditor5_image_uploads_created_at_index');
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (! Schema::hasTable('ckeditor5_image_uploads')) {
            return;
        }

        Schema::table('ckeditor5_image_uploads', function (Blueprint $table) {
            $table->dropIndex('ckeditor5_image_uploads_created_at_index');
        });
    }
};
