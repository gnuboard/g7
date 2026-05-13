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
        Schema::table('template_layouts', function (Blueprint $table) {
            $table->string('original_content_hash', 64)
                ->nullable()
                ->after('content')
                ->comment('원본 레이아웃 콘텐츠 SHA-256 해시 (수정 감지용)');
            $table->unsignedInteger('original_content_size')
                ->nullable()
                ->after('original_content_hash')
                ->comment('원본 레이아웃 콘텐츠 바이트 크기 (변화량 표시용)');
        });

        // 기존 데이터 hash 백필 (현재 content = 원본으로 간주)
        DB::table('template_layouts')
            ->whereNull('original_content_hash')
            ->orderBy('id')
            ->chunk(100, function ($layouts) {
                foreach ($layouts as $layout) {
                    $content = json_decode($layout->content, true);
                    $normalized = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $hash = hash('sha256', $normalized);
                    $size = strlen($normalized);

                    DB::table('template_layouts')
                        ->where('id', $layout->id)
                        ->update([
                            'original_content_hash' => $hash,
                            'original_content_size' => $size,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_layouts', function (Blueprint $table) {
            $table->dropColumn(['original_content_hash', 'original_content_size']);
        });
    }
};
