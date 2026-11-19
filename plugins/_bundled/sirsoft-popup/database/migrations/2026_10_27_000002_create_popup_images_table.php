<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 팝업 이미지(PC·모바일) 테이블을 만듭니다. 업로드는 temp_key 로 묶어 두었다가 팝업 저장 때 연결합니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('popup_images')) {
            return;
        }

        Schema::create('popup_images', function (Blueprint $table) {
            $table->id()->comment('이미지 ID');
            $table->foreignId('popup_id')->nullable()->comment('팝업 ID (저장 전 임시 업로드는 null)')
                ->constrained('popups')->cascadeOnDelete();
            $table->string('temp_key', 64)->nullable()->comment('등록·수정 화면 임시 업로드 묶음 키 (연결되면 null)');
            $table->string('device', 10)->comment('기기: pc, mobile');
            $table->string('hash', 64)->unique()->comment('공개 주소 키 (무작위 64자)');
            $table->string('disk', 50)->nullable()->comment('저장 디스크');
            $table->string('path', 500)->comment('스토리지 카테고리 images 아래 상대 경로');
            $table->string('original_name', 255)->comment('원본 파일명');
            $table->string('mime', 100)->comment('MIME 타입');
            $table->unsignedInteger('size')->comment('파일 크기 (바이트)');
            $table->unsignedInteger('width')->nullable()->comment('가로 픽셀');
            $table->unsignedInteger('height')->nullable()->comment('세로 픽셀');
            $table->unsignedBigInteger('created_by')->nullable()->comment('올린 회원 ID');
            $table->timestamps();

            $table->index(['popup_id', 'device'], 'popup_images_popup_device_idx');
            $table->index(['temp_key', 'created_at'], 'popup_images_temp_idx');
        });
    }

    /**
     * 팝업 이미지 테이블을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('popup_images');
    }
};
