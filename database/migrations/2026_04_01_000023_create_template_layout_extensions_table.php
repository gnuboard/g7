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
        Schema::create('template_layout_extensions', function (Blueprint $table) {
            $table->id()->comment('확장 ID');
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete()->comment('템플릿 ID (g7_templates 참조)');
            $table->string('extension_type', 50)->comment('확장 타입: extension_point=확장점 방식, overlay=ID 기반 오버레이 방식');
            $table->string('target_name')->comment('타겟 이름 (extension_point: 확장점명, overlay: 레이아웃명)');
            $table->string('source_type', 50)->comment('출처 타입: template=템플릿(오버라이드용), module=모듈, plugin=플러그인');
            $table->string('source_identifier', 255)->comment('출처 식별자 (예: sirsoft-ecommerce). 템플릿 오버라이드의 경우 오버라이드 대상 모듈/플러그인 식별자');
            $table->string('override_target', 255)->nullable()->comment('오버라이드 대상 (source_type=template일 때만 사용). 모듈/플러그인 식별자');
            $table->longText('content')->comment('확장 정의 JSON (components, injections, data_sources 포함)');
            $table->integer('priority')->default(100)->comment('우선순위 (낮을수록 먼저 적용, 기본값: 100)');
            $table->boolean('is_active')->default(true)->comment('활성 상태 (true=활성, false=비활성)');
            $table->timestamps();
            $table->softDeletes()->comment('삭제 일시 (soft delete)');

            $table->index(['template_id', 'extension_type', 'target_name', 'is_active'], 'idx_target');
            $table->index(['source_type', 'source_identifier'], 'idx_source');
            $table->index(['override_target', 'template_id', 'extension_type', 'target_name'], 'idx_override');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('template_layout_extensions', function (Blueprint $table) {
                $table->comment('템플릿 레이아웃 확장 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_layout_extensions');
    }
};
