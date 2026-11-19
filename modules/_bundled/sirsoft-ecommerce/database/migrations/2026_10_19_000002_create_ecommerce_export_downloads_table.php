<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 내보내기 파일을 누가 언제 받아 갔는지의 원장이다. 파일 스트림을 시작한 시점에 1행을 남긴다
     * (브라우저가 끝까지 받았는지는 서버가 보증할 수 없다). 내보내기 작업이 삭제돼도 이 행은 남는다 —
     * 작업 행은 소프트 삭제이고, 외래키는 RESTRICT 라 DB CASCADE 로 지워지지 않는다.
     */
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_export_downloads')) {
            return;
        }

        Schema::create('ecommerce_export_downloads', function (Blueprint $table) {
            $table->id()->comment('다운로드 이력 ID');
            $table->foreignId('export_id')->comment('내보내기 작업 ID')->constrained('ecommerce_exports')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->comment('내려받은 회원 ID (회원 삭제 시 NULL)')->constrained('users')->nullOnDelete();
            $table->string('user_name_snapshot', 100)->nullable()->comment('내려받은 회원 표시명 스냅샷');
            $table->string('ip_address', 45)->nullable()->comment('요청 IP');
            $table->string('user_agent', 500)->nullable()->comment('요청 User-Agent');
            $table->timestamp('downloaded_at')->comment('다운로드(전송 시작) 시각');
            $table->timestamps();

            $table->index(['export_id', 'downloaded_at'], 'ecommerce_export_downloads_export_downloaded_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_export_downloads');
    }
};
