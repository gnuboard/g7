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
        Schema::create('page_versions', function (Blueprint $table) {
            $table->id()->comment('버전 ID');
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete()->comment('페이지 ID');
            $table->unsignedInteger('version')->comment('버전 번호');
            $table->text('title')->comment('제목 스냅샷 (다국어 JSON)');
            $table->mediumText('content')->nullable()->comment('본문 스냅샷 (다국어 JSON)');
            $table->string('content_mode', 10)->default('html')->comment('본문 형식 스냅샷 (html, text)');
            $table->text('seo_meta')->nullable()->comment('SEO 메타 스냅샷 (title, description, keywords)');
            $table->text('changes_summary')->nullable()->comment('변경 요약 (변경 필드 목록, 복원 정보)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('작성자 ID');
            $table->timestamps();

            $table->index(['page_id', 'version']);
            $table->index('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('page_versions', function (Blueprint $table) {
                $table->comment('페이지 버전 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('page_versions')) {
            Schema::dropIfExists('page_versions');
        }
    }
};
