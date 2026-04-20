<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        Schema::create('ckeditor5_image_uploads', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('hash', 12)->unique()->comment('URL용 고유 해시 (12자)');
            $table->string('original_name')->comment('원본 파일명');
            $table->string('file_path', 1000)->comment('저장 파일 경로');
            $table->string('storage_disk', 50)->default('public')->comment('스토리지 디스크');
            $table->unsignedBigInteger('file_size')->comment('파일 크기(bytes)');
            $table->string('mime_type', 100)->comment('MIME 타입');
            $table->unsignedBigInteger('uploaded_by')->nullable()->comment('업로드 사용자 ID');
            $table->timestamps();

            $table->index('uploaded_by');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('ckeditor5_image_uploads', function (Blueprint $table) {
                $table->comment('CKEditor5 이미지 업로드 기록');
            });
        }
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (Schema::hasTable('ckeditor5_image_uploads')) {
            Schema::dropIfExists('ckeditor5_image_uploads');
        }
    }
};
