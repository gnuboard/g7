<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schedule_histories 가 「거절됨」 행을 담도록 넓힙니다 (7.2.0).
 *
 * 큐로 보낸 수동 실행은 요청 시점에 202 로 접수되고, 워커가 집는 순간에야 거절(앞선 실행 진행 중,
 * 잠금 저장소 오류, 소유 확장 비활성)이 정해진다. 그 거절을 누른 사람이 화면에서 볼 수 있게 이력 행으로 남긴다.
 */
return new class extends Migration
{
    /**
     * status 에 rejected 를 더하고 거절 사유 칸을 추가합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedule_histories')) {
            return;
        }

        if (! $this->statusHas('rejected')) {
            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->enum('status', ['success', 'failed', 'running', 'rejected'])->default('running')
                    ->comment('실행 상태: success(성공), failed(실패), running(실행중), rejected(거절됨 — 큐로 보낸 수동 실행을 시작하지 않음)')->change();
            });
        }

        if (! Schema::hasColumn('schedule_histories', 'reject_reason')) {
            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->string('reject_reason', 40)->nullable()->after('status')
                    ->comment('거절 사유 (status=rejected 일 때): already_running(앞선 실행 진행 중), run_lock_unavailable(잠금 저장소 오류), owner_inactive(소유 확장 비활성)');
            });
        }
    }

    /**
     * 거절 사유 칸을 지우고 status 를 되돌립니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('schedule_histories')) {
            return;
        }

        if (Schema::hasColumn('schedule_histories', 'reject_reason')) {
            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->dropColumn('reject_reason');
            });
        }

        if ($this->statusHas('rejected')) {
            // 거절 행은 좁힌 enum 에 담을 수 없다 — 실행이 아니었던 기록이라 지운다.
            DB::table('schedule_histories')->where('status', 'rejected')->delete();

            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->enum('status', ['success', 'failed', 'running'])->default('running')
                    ->comment('실행 상태: success(성공), failed(실패), running(실행중)')->change();
            });
        }
    }

    /**
     * status enum 이 주어진 값을 갖는지 확인합니다.
     *
     * @param  string  $value  확인할 값
     * @return bool 포함 여부
     */
    private function statusHas(string $value): bool
    {
        foreach (Schema::getColumns('schedule_histories') as $info) {
            if ($info['name'] === 'status') {
                return str_contains((string) $info['type'], "'{$value}'");
            }
        }

        return false;
    }
};
