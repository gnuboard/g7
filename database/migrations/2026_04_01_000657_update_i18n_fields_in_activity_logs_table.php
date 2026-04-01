<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activity_logs 테이블 i18n 필드 변경.
 *
 * - description 컬럼 삭제 (번역된 텍스트 저장 → i18n 키 기반으로 전환)
 * - description_key, description_params 컬럼 추가 (실시간 다국어 번역)
 * - changes 컬럼 추가 (구조화된 변경 이력)
 * - 복합 인덱스 추가 (loggable + created_at, log_type + action + created_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite 호환: 컬럼 삭제와 추가를 별도 블록으로 분리
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('description_key', 150)->nullable()->after('action')
                ->comment('다국어 번역 키 (예: activity_log.description.user_create)');
            $table->text('description_params')->nullable()->after('description_key')
                ->comment('다국어 번역 파라미터 (예: {"user_id": "abc-123"})');
            $table->mediumText('changes')->nullable()->after('properties')
                ->comment('구조화된 변경 이력 (필드별 label_key, old, new, type)');

            $table->index(
                ['loggable_type', 'loggable_id', 'created_at'],
                'idx_activity_logs_loggable'
            );
            $table->index(
                ['log_type', 'action', 'created_at'],
                'idx_activity_logs_type_action_date'
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('idx_activity_logs_loggable');
            $table->dropIndex('idx_activity_logs_type_action_date');
            $table->dropColumn(['description_key', 'description_params', 'changes']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->text('description')->nullable()->after('action')
                ->comment('활동 설명');
        });
    }
};
