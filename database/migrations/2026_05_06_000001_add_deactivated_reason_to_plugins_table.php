<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 플러그인 테이블에 비활성화 사유 메타 컬럼 추가.
     *
     * 사용자 수동 비활성화(manual)와 코어 버전 비호환에 의한 자동 비활성화(incompatible_core)를
     * 구분하여 (a) UI 라벨링, (b) 알림 영속화, (c) 재호환 시 원클릭 복구 판정에 사용합니다.
     */
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            if (! Schema::hasColumn('plugins', 'deactivated_reason')) {
                $table->string('deactivated_reason', 32)
                    ->nullable()
                    ->after('status')
                    ->comment('비활성화 사유: manual(사용자 수동) | incompatible_core(코어 버전 호환성) | null(active)');
            }

            if (! Schema::hasColumn('plugins', 'deactivated_at')) {
                $table->timestamp('deactivated_at')
                    ->nullable()
                    ->after('deactivated_reason')
                    ->comment('비활성화 시점');
            }

            if (! Schema::hasColumn('plugins', 'incompatible_required_version')) {
                $table->string('incompatible_required_version', 64)
                    ->nullable()
                    ->after('deactivated_at')
                    ->comment('incompatible_core 시 요구된 g7_version 제약 (재호환 판정용)');
            }
        });

        Schema::table('plugins', function (Blueprint $table) {
            $table->index('deactivated_reason', 'plugins_deactivated_reason_index');
        });
    }

    /**
     * 마이그레이션 롤백.
     */
    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropIndex('plugins_deactivated_reason_index');
        });

        Schema::table('plugins', function (Blueprint $table) {
            foreach (['incompatible_required_version', 'deactivated_at', 'deactivated_reason'] as $column) {
                if (Schema::hasColumn('plugins', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
