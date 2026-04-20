<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 알림 템플릿에 수신자 규칙 컬럼을 추가합니다.
     *
     * 채널별 독립 수신자 설정을 위해 templates 레벨에 recipients를 배치합니다.
     */
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->text('recipients')
                ->nullable()
                ->comment('수신자 규칙 JSON ([{type, value, relation, exclude_trigger_user}])')
                ->after('click_url');
        });
    }

    /**
     * 수신자 규칙 컬럼을 제거합니다.
     */
    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            if (Schema::hasColumn('notification_templates', 'recipients')) {
                $table->dropColumn('recipients');
            }
        });
    }
};
