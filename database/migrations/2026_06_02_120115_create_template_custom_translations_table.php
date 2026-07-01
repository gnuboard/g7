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
        Schema::create('template_custom_translations', function (Blueprint $table) {
            $table->id()->comment('커스텀 다국어 키 ID');
            $table->foreignId('template_id')
                ->constrained('templates')
                ->cascadeOnDelete()
                ->comment('소속 템플릿 ID');
            $table->string('layout_name', 150)->nullable()->comment('생성 출처 레이아웃 이름');
            $table->string('translation_key', 255)->comment('다국어 키 ($t: 참조 경로)');
            $table->json('values')->comment('로케일별 번역 값');
            $table->json('user_overrides')->nullable()->comment('사용자 수정 보존 추적');
            $table->enum('status', ['active', 'orphaned'])->default('active')->comment('상태 (active: 활성, orphaned: 고아)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->comment('생성자');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->comment('수정자');
            $table->timestamps();

            $table->unique(['template_id', 'translation_key'], 'template_custom_translations_template_key_unique');
            $table->index(['template_id', 'layout_name'], 'template_custom_translations_template_layout_index');
            $table->index('status', 'template_custom_translations_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_custom_translations');
    }
};
