<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 정의에 분류(category) 컬럼을 더합니다.
 *
 * 본인인증 메시지가 알림 시스템으로 합쳐지면서(7.1.0) 같은 표 안에 「일반 알림」과
 * 「본인인증 알림」이 함께 놓인다. 화면 묶음(분류 칩)·수신자 편집 숨김·운영자 추가/삭제 허용·
 * 발송 로그 마스킹이 모두 이 값으로 갈린다 — type 접두어 문자열 비교보다 명시적이고 인덱스를 탄다.
 *
 * 값: 본인인증 알림 = `identity`, 그 외 = null(일반 알림).
 */
return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('notification_definitions', 'category')) {
            Schema::table('notification_definitions', function (Blueprint $table) {
                $table->string('category', 30)
                    ->nullable()
                    ->after('extension_identifier')
                    ->comment('알림 분류 (identity = 본인인증 알림, null = 일반 알림)');
            });
        }

        // 컬럼과 인덱스를 따로 가드한다 — MySQL 은 DDL 을 롤백하지 않아, 컬럼 추가 뒤 인덱스 단계에서
        // 멈추면 재실행 시 컬럼 가드만으로는 인덱스를 다시 만들지 못한다.
        if (! Schema::hasIndex('notification_definitions', 'idx_notification_def_category')) {
            Schema::table('notification_definitions', function (Blueprint $table) {
                $table->index('category', 'idx_notification_def_category');
            });
        }
    }

    /**
     * 마이그레이션을 되돌립니다.
     */
    public function down(): void
    {
        if (Schema::hasIndex('notification_definitions', 'idx_notification_def_category')) {
            Schema::table('notification_definitions', function (Blueprint $table) {
                $table->dropIndex('idx_notification_def_category');
            });
        }

        if (Schema::hasColumn('notification_definitions', 'category')) {
            Schema::table('notification_definitions', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
