<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('template_layout_previews', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->char('token', 36)->unique()->comment('미리보기 URL 토큰 (UUID)');
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete()->comment('템플릿 ID');
            $table->string('layout_name', 255)->comment('미리보기 대상 레이아웃 이름');
            $table->longText('content')->comment('편집 중인 레이아웃 JSON');
            $table->unsignedBigInteger('admin_id')->comment('미리보기 생성 관리자 ID');
            $table->timestamp('expires_at')->comment('만료 시각');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index('expires_at', 'idx_layout_previews_expires_at');
            $table->index(['template_id', 'layout_name', 'admin_id'], 'idx_layout_previews_template_layout_admin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_layout_previews');
    }
};
