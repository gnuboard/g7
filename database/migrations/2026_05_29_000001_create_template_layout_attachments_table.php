<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 레이아웃 편집 중 업로드되는 파일(배경 이미지 등)을 관리하는 테이블.
     * 파일 저장은 코어 StorageInterface 를 통해 수행하고, 저장 위치(disk/path)와
     * 메타데이터를 본 테이블에 기록한다.
     */
    public function up(): void
    {
        Schema::create('template_layout_attachments', function (Blueprint $table) {
            $table->id()->comment('첨부 파일 ID');
            $table->foreignId('template_id')
                ->constrained('templates')
                ->cascadeOnDelete()
                ->comment('소속 템플릿 ID');
            $table->string('layout_name', 150)->nullable()->comment('사용 출처 레이아웃 이름');
            $table->string('disk', 50)->comment('스토리지 디스크 이름');
            $table->string('path', 500)->comment('스토리지 내 파일 경로');
            $table->string('original_name', 255)->comment('업로드 원본 파일명');
            $table->string('mime_type', 100)->comment('MIME 타입');
            $table->unsignedBigInteger('size')->comment('파일 크기(바이트)');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('업로더 사용자 ID');
            $table->timestamps();

            $table->index(['template_id', 'layout_name'], 'index_template_layout');
            $table->index(['disk', 'path'], 'index_disk_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('template_layout_attachments')) {
            Schema::dropIfExists('template_layout_attachments');
        }
    }
};
