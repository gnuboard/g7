<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schedule_histories 에 (schedule_id, started_at) 인덱스를 추가합니다 (17, 7.2.0).
 *
 * 스케줄별 최근 이력 조회(목록의 마지막 실행·이력 모달)가 스케줄 단위로 started_at 정렬을 한다.
 */
return new class extends Migration
{
    /**
     * 인덱스를 추가합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedule_histories')
            || Schema::hasIndex('schedule_histories', 'idx_schedule_histories_schedule_started')) {
            return;
        }

        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->index(['schedule_id', 'started_at'], 'idx_schedule_histories_schedule_started');
        });
    }

    /**
     * 인덱스를 제거합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('schedule_histories')
            || ! Schema::hasIndex('schedule_histories', 'idx_schedule_histories_schedule_started')) {
            return;
        }

        // MySQL 은 외래 키가 자동으로 만든 인덱스를 다른 인덱스가 그 역할을 대신하면 지운다.
        // 이 복합 인덱스가 schedule_id 외래 키의 유일한 인덱스라면 먼저 단일 인덱스를 세워야 지울 수 있다.
        if (! $this->hasOtherScheduleIdIndex()) {
            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->index('schedule_id', 'idx_schedule_histories_schedule_id');
            });
        }

        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->dropIndex('idx_schedule_histories_schedule_started');
        });
    }

    /**
     * 이 인덱스 말고 schedule_id 로 시작하는 인덱스가 있는지 확인합니다.
     *
     * @return bool 존재 여부
     */
    private function hasOtherScheduleIdIndex(): bool
    {
        foreach (Schema::getIndexes('schedule_histories') as $index) {
            if ($index['name'] !== 'idx_schedule_histories_schedule_started'
                && ($index['columns'][0] ?? null) === 'schedule_id') {
                return true;
            }
        }

        return false;
    }
};
