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
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('type', 100)->unique()->comment('템플릿 유형 (welcome, reset_password 등)');
            $table->text('subject')->comment('다국어 제목 ({"ko": "...", "en": "..."})');
            $table->mediumText('body')->comment('다국어 HTML 본문 ({"ko": "...", "en": "..."})');
            $table->text('variables')->nullable()->comment('사용 가능 변수 메타데이터 ([{key, description}])');
            $table->boolean('is_active')->default(true)->comment('활성 여부 (false = 해당 유형 메일 발송 중단)');
            $table->boolean('is_default')->default(true)->comment('시더 생성 항목 여부');
            $table->text('user_overrides')->nullable()->comment('사용자가 수정한 필드명 목록 (코어 업데이트 시 보존)');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('mail_templates', function (Blueprint $table) {
                $table->comment('코어 메일 템플릿');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
