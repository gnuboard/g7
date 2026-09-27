<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\QueueStatsRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 큐 적체 Repository
 *
 * 큐에 일이 얼마나 쌓여 있는지 셉니다. 데이터베이스 큐일 때만 셀 수 있습니다 —
 * Redis·SQS 는 여기서 보이지 않으므로 화면이 그 사실을 함께 적습니다.
 */
class QueueStatsRepository implements QueueStatsRepositoryInterface
{
    /**
     * 처리를 기다리는 일의 수를 셉니다.
     *
     * @return int 대기 중인 일의 수
     */
    public function pendingJobs(): int
    {
        return $this->countTable((string) config('queue.connections.database.table', 'jobs'));
    }

    /**
     * 실패한 일의 수를 셉니다.
     *
     * @return int 실패한 일의 수
     */
    public function failedJobs(): int
    {
        return $this->countTable((string) config('queue.failed.table', 'failed_jobs'));
    }

    /**
     * 큐 적체를 셀 수 있는 상태인지 확인합니다.
     *
     * @return bool 데이터베이스 큐라서 셀 수 있으면 true
     */
    public function isCountable(): bool
    {
        return config('queue.default') === 'database';
    }

    /**
     * 큐 표의 행 수를 셉니다.
     *
     * @param  string  $table  표 이름
     * @return int 행 수 (셀 수 없으면 0)
     */
    private function countTable(string $table): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return (int) DB::table($table)->count();
        } catch (Throwable) {
            // 진단 화면은 큐를 못 읽는다고 해서 깨지면 안 된다 — 이 화면을 보러 오는
            // 이유가 대개 「뭔가 잘못됐다」이기 때문이다.
            return 0;
        }
    }
}
