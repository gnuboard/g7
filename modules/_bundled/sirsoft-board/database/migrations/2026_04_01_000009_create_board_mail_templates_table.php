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
        Schema::create('board_mail_templates', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('type', 100)->unique()->comment('템플릿 유형 (new_comment, reply_comment 등)');
            $table->text('subject')->comment('다국어 제목 ({"ko": "...", "en": "..."})');
            $table->mediumText('body')->comment('다국어 HTML 본문 ({"ko": "...", "en": "..."})');
            $table->text('variables')->nullable()->comment('사용 가능 변수 메타데이터 ([{key, description}])');
            $table->boolean('is_active')->default(true)->comment('활성 여부');
            $table->boolean('is_default')->default(true)->comment('시더 생성 항목 여부');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_mail_templates')) {
            Schema::dropIfExists('board_mail_templates');
        }
    }
};
