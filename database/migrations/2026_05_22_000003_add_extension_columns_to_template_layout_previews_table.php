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
        Schema::table('template_layout_previews', function (Blueprint $table) {
            if (! Schema::hasColumn('template_layout_previews', 'preview_type')) {
                $table->string('preview_type', 20)
                    ->default('layout')
                    ->after('layout_name')
                    ->comment('미리보기 종류 (layout: 일반 레이아웃, extension: 레이아웃 확장)');
            }
            if (! Schema::hasColumn('template_layout_previews', 'extension_id')) {
                $table->unsignedBigInteger('extension_id')
                    ->nullable()
                    ->after('preview_type')
                    ->comment('레이아웃 확장 ID (preview_type=extension 일 때)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_layout_previews', function (Blueprint $table) {
            $table->dropColumn(['preview_type', 'extension_id']);
        });
    }
};
