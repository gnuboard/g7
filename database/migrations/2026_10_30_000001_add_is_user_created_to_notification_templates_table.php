<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 템플릿에 운영자 생성 표식을 추가합니다 (7.2.0).
 *
 * 운영자가 알림의 발송 수단을 켰는데 그 수단의 템플릿을 어느 확장도 선언하지 않았으면 코어가 빈 템플릿을 만든다.
 * 그 행은 확장 업데이트의 stale 정리(선언에 없는 채널 삭제)에서 빠져야 하므로 선언 행과 가를 표식이 필요하다.
 * 기존 행은 모두 선언에서 왔으므로 기본값 false 로 충분하다.
 */
return new class extends Migration
{
    /**
     * 운영자 생성 표식 컬럼을 추가합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates') || Schema::hasColumn('notification_templates', 'is_user_created')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->boolean('is_user_created')
                ->default(false)
                ->comment('운영자가 발송 수단을 켜서 만든 템플릿 여부 (확장 선언에 없음, 동기화 정리 제외)')
                ->after('is_default');
        });
    }

    /**
     * 운영자 생성 표식 컬럼을 제거합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('notification_templates') || ! Schema::hasColumn('notification_templates', 'is_user_created')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn('is_user_created');
        });
    }
};
