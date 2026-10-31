<?php

namespace Tests\Feature\Queue;

use App\Models\QueueJobLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * queue:prune-records 테스트 (#17 — 처리 기록·실패 작업 공통 보관 기간, 기본 7일)
 */
class PruneQueueRecordsCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 기록 표를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('failed_jobs')->delete();
        QueueJobLog::query()->delete();
    }

    /**
     * 나이(일)별 처리 기록·실패 작업을 1건씩 심습니다.
     *
     * @param  list<int>  $ages  나이(일) 목록
     * @return void 반환값 없음
     */
    private function seedRecords(array $ages): void
    {
        foreach ($ages as $age) {
            $at = Carbon::now()->subDays($age)->subMinute()->format('Y-m-d H:i:s');
            QueueJobLog::query()->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'job_class' => 'App\\Jobs\\Age'.$age, 'display_name' => 'Age'.$age, 'status' => 'processed',
                'attempts' => 1, 'created_at' => $at,
            ]);
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'x', 'failed_at' => $at,
            ]);
        }
    }

    /**
     * 옵션이 없으면 설정 보관 기간(없으면 7일)으로 두 표를 함께 정리한다.
     *
     * @scenario retention_source=default|setting
     *
     * @effects prune_uses_retention_setting, prune_covers_logs_and_failed
     *
     * @return void 반환값 없음
     */
    public function test_설정_보관기간으로_두_표를_정리한다(): void
    {
        config(['g7_settings.core.drivers.queue_record_retention_days' => null]);
        $this->seedRecords([1, 6, 8, 30]);

        $this->artisan('queue:prune-records')->assertExitCode(0);
        $this->assertSame(2, QueueJobLog::query()->count());
        $this->assertSame(2, DB::table('failed_jobs')->count());

        config(['g7_settings.core.drivers.queue_record_retention_days' => 3]);
        $this->artisan('queue:prune-records')->assertExitCode(0);
        $this->assertSame(1, QueueJobLog::query()->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    /**
     * --days 가 설정보다 우선하고, 범위(1~90) 밖이면 아무것도 지우지 않고 INVALID 로 끝난다.
     *
     * @scenario retention_source=days_option, days=valid|0|91|non_numeric
     *
     * @effects prune_days_option_bounds
     *
     * @return void 반환값 없음
     */
    public function test_days_옵션은_범위를_검사한다(): void
    {
        config(['g7_settings.core.drivers.queue_record_retention_days' => 90]);
        $this->seedRecords([2, 10]);

        foreach (['0', '91', 'abc'] as $bad) {
            $this->artisan('queue:prune-records', ['--days' => $bad])->assertExitCode(2);
        }
        $this->assertSame(2, QueueJobLog::query()->count());

        $this->artisan('queue:prune-records', ['--days' => '5'])->assertExitCode(0);
        $this->assertSame(1, QueueJobLog::query()->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}
