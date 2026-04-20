<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 알림 템플릿에 click_url 컬럼 추가.
     *
     * 알림센터에서 알림 클릭 시 이동할 URL 패턴을 저장합니다.
     * 변수 플레이스홀더 사용 가능 (예: /mypage/orders/{order_number}).
     * nullable — 미정의 시 프론트엔드 fallback 경로 사용.
     */
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->string('click_url', 500)
                ->nullable()
                ->after('body')
                ->comment('알림 클릭 시 이동 URL 패턴 (변수 치환 가능, 예: /mypage/orders/{order_number})');
        });
    }

    /**
     * click_url 컬럼 제거.
     */
    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            if (Schema::hasColumn('notification_templates', 'click_url')) {
                $table->dropColumn('click_url');
            }
        });
    }
};
