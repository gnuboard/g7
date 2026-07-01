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
        Schema::create('template_layout_extension_versions', function (Blueprint $table) {
            $table->id()->comment('버전 ID');
            $table->foreignId('extension_id')->constrained('template_layout_extensions')->cascadeOnDelete()->comment('레이아웃 확장 ID');
            $table->unsignedInteger('version')->comment('버전 번호 (자동 증가)');
            $table->longText('content')->comment('확장 정의 JSON 스냅샷');
            $table->text('changes_summary')->nullable()->comment('변경 요약 JSON: {"added": 3, "removed": 2, "is_restored": false, "restored_from": null}');
            $table->unsignedBigInteger('created_by')->nullable()->comment('저장자 ID');
            $table->timestamp('created_at')->nullable()->comment('생성 일시');

            $table->unique(['extension_id', 'version'], 'uk_ext_layout_version');
            $table->index(['extension_id', 'created_at'], 'idx_ext_layout_versions');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('template_layout_extension_versions', function (Blueprint $table) {
                $table->comment('템플릿 레이아웃 확장 버전 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_layout_extension_versions');
    }
};
