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
        Schema::create('pages', function (Blueprint $table) {
            $table->id()->comment('페이지 ID');
            $table->string('slug', 100)->unique()->comment('URL 슬러그 (고유)');
            $table->text('title')->comment('페이지 제목 (다국어 JSON)');
            $table->mediumText('content')->nullable()->comment('페이지 본문 (다국어 JSON)');
            $table->string('content_mode', 10)->default('html')->comment('본문 형식 (html, text)');
            $table->boolean('published')->default(false)->comment('발행 여부 (true: 발행, false: 미발행)');
            $table->timestamp('published_at')->nullable()->comment('발행 일시');
            $table->text('seo_meta')->nullable()->comment('SEO 메타 정보 (title, description, keywords)');
            $table->unsignedInteger('current_version')->default(1)->comment('현재 버전 번호');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();
            $table->softDeletes();

            $table->index('published');
            $table->index('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('pages', function (Blueprint $table) {
                $table->comment('페이지 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pages')) {
            Schema::dropIfExists('pages');
        }
    }
};
