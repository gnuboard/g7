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
        Schema::create('template_layouts', function (Blueprint $table) {
            $table->id()->comment('레이아웃 ID');
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete()->comment('템플릿 ID');
            $table->string('name')->comment('레이아웃 이름 (예: dashboard, users, user_edit)');
            $table->longText('content')->comment('레이아웃 JSON 내용');
            $table->string('extends')->nullable()->index()->comment('부모 레이아웃 이름 (예: layouts/_admin_base)');
            $table->enum('source_type', ['template', 'module', 'plugin'])->default('template')->comment('레이아웃 소스 타입 (template: 템플릿 자체/오버라이드, module: 모듈 기본, plugin: 플러그인 기본)');
            $table->string('source_identifier', 255)->nullable()->comment('모듈/플러그인 식별자 (source_type이 module/plugin일 때 사용)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['template_id', 'name', 'source_type'], 'uk_template_layout_source');
            $table->index(['source_type', 'source_identifier'], 'idx_source');
            $table->index('deleted_at');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('template_layouts', function (Blueprint $table) {
                $table->comment('템플릿 레이아웃 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_layouts');
    }
};
