<?php

namespace Tests\Feature\Queue;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Jobs\Analytics\AnonymizeLedgerJob;
use App\Jobs\Analytics\ArchiveRangeJob;
use App\Jobs\RunPrivacyManualActionJob;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Privacy\ManualRun\PrivacyManualRunService;
use App\Services\Analytics\AnonymizeService;
use App\Services\Analytics\ArchiveService;
use App\Support\Queue\JobSingleFlight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 장시간 잡을 다른 워커가 다시 집어도 아직 도는 실행 이력이 「실패」로 닫히지 않는다 (#139 회귀)
 *
 * 통계 재집계·익명화 잡은 기본 큐(retry_after 90초)에서 최대 1시간을 돈다. 워커가 둘이면 90초 뒤 두 번째 워커가
 * 같은 잡을 다시 집는데, 종전 `tries=1` 은 그 사본을 handle 없이 MaxAttemptsExceeded 로 실패 처리했고 `failed()` 가
 * 아직 도는 실행의 이력을 「실패」로 닫았다. 화면의 실행 이력에는 실제로 도는 작업이 실패로 보였다.
 *
 * 재현: 서비스 호출 안에서 그 잡의 `reserved_at` 을 과거로 돌리고 같은 프로세스에서 워커를 한 번 더 돌린다.
 */
class LongJobReReservationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 큐를 database 로 바꾸고 단일 실행 잠금이 남지 않게 캐시를 배열로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'cache.default' => 'array']);
        // queue:work 는 실패 기록 리스너를 프로세스에 한 번만 붙인다 — 테스트마다 새 앱이므로 다시 붙게 한다
        (new \ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))->setValue(null, false);
    }

    /**
     * 재집계 잡을 다시 집은 사본은 조용히 끝나고, 도는 실행 이력은 그대로 「실행 중」이다.
     *
     * @scenario job=archive_range, event=rereserve
     *
     * @effects rereserved_copy_exits_quietly
     *
     * @return void 반환값 없음
     */
    public function test_archive_range_rereserved_copy_exits_quietly_and_keeps_running_run_open(): void
    {
        $failedBefore = DB::table('failed_jobs')->count();
        $calls = 0;
        $this->mock(ArchiveService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('archiveRange')->andReturnUsing(function () use (&$calls) {
                if (++$calls === 1) {
                    $this->rereserveAndRunAnotherWorker();
                }

                return [];
            });
        });

        ArchiveRangeJob::dispatch('2026-01-01', '2026-01-02');
        $run = $this->createRunningRun(ArchiveRunType::Reaggregate, '2026-01-01');

        $this->runWorker();

        $this->assertSame(1, $calls, '다시 집은 사본이 집계를 한 번 더 돌렸다');
        $this->assertSame(ArchiveRunStatus::Running, $run->fresh()->status, '아직 도는 실행 이력이 「실패」로 닫혔다');
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count(), '다시 집은 사본이 실패로 기록됐다');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * 익명화 잡을 다시 집은 사본도 조용히 끝나고 실행 이력은 그대로다.
     *
     * @scenario job=anonymize_ledger, event=rereserve
     *
     * @effects rereserved_copy_exits_quietly
     *
     * @return void 반환값 없음
     */
    public function test_anonymize_ledger_rereserved_copy_exits_quietly_and_keeps_running_run_open(): void
    {
        $failedBefore = DB::table('failed_jobs')->count();
        $calls = 0;
        $this->mock(AnonymizeService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('anonymize')->andReturnUsing(function () use (&$calls) {
                if (++$calls === 1) {
                    $this->rereserveAndRunAnotherWorker();
                }

                return new AnalyticsArchiveRun;
            });
        });

        AnonymizeLedgerJob::dispatch(1, false);
        $run = $this->createRunningRun(ArchiveRunType::Anonymize, '2026-01-01');

        $this->runWorker();

        $this->assertSame(1, $calls);
        $this->assertSame(ArchiveRunStatus::Running, $run->fresh()->status);
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * 개인정보 직권 실행 잡을 다시 집은 사본은 서비스를 다시 부르지 않고 조용히 끝난다.
     *
     * @scenario job=privacy_manual_run, event=rereserve
     *
     * @effects rereserved_copy_exits_quietly
     *
     * @return void 반환값 없음
     */
    public function test_privacy_manual_run_rereserved_copy_exits_quietly(): void
    {
        $failedBefore = DB::table('failed_jobs')->count();
        $calls = 0;
        $this->mock(PrivacyManualRunService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('execute')->andReturnUsing(function () use (&$calls) {
                if (++$calls === 1) {
                    $this->rereserveAndRunAnotherWorker();
                }

                return 'done';
            });
            $mock->shouldReceive('failFromJob')->never();
        });

        RunPrivacyManualActionJob::dispatch(987654);
        $this->runWorker();

        $this->assertSame(1, $calls, '다시 집은 사본이 직권 실행을 한 번 더 불렀다');
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * 진짜 예외는 재시도 없이 한 번에 실패하고 실행 이력을 닫는다(종전 동작 유지).
     *
     * @scenario job=archive_range, event=exception
     *
     * @effects genuine_failure_closes_run_once
     *
     * @return void 반환값 없음
     */
    public function test_genuine_exception_fails_once_and_closes_run(): void
    {
        $failedBefore = DB::table('failed_jobs')->count();
        $calls = 0;
        $this->mock(ArchiveService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('archiveRange')->andReturnUsing(function () use (&$calls) {
                $calls++;

                throw new RuntimeException('qa139 집계 실패');
            });
        });

        ArchiveRangeJob::dispatch('2026-01-01', '2026-01-02');
        $run = $this->createRunningRun(ArchiveRunType::Reaggregate, '2026-01-01');

        $this->runWorker();

        $this->assertSame(1, $calls, '진짜 예외는 재시도하지 않는다');
        $this->assertSame(ArchiveRunStatus::Failed, $run->fresh()->status);
        $this->assertSame($failedBefore + 1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * 단일 실행 잠금이 잡혀 있는 동안의 MaxAttemptsExceeded 는 이력을 닫지 않는다.
     *
     * @scenario job=archive_range, event=max_attempts
     *
     * @effects failed_hook_respects_single_flight
     *
     * @return void 반환값 없음
     */
    public function test_failed_hook_ignores_max_attempts_while_single_flight_held(): void
    {
        $job = new ArchiveRangeJob('2026-01-01', '2026-01-02');
        $run = $this->createRunningRun(ArchiveRunType::Reaggregate, '2026-01-01');
        $lock = Cache::lock(JobSingleFlight::key(ArchiveRangeJob::class, $job->dispatchId), 600);
        $this->assertTrue($lock->get());

        $job->failed(new MaxAttemptsExceededException('qa139'));

        $this->assertSame(ArchiveRunStatus::Running, $run->fresh()->status);
        $lock->release();
    }

    /**
     * 시간 초과 실패는 잠금이 잡혀 있어도(같은 프로세스가 아직 쥔 채) 이력을 닫는다.
     *
     * Windows PHP 는 pcntl 이 없어 워커의 timeout 강제 종료 경로를 실제로 재현하지 못한다 — failed() 를 직접 부른다.
     *
     * @scenario job=archive_range, event=timeout
     *
     * @effects timeout_closes_run
     *
     * @return void 반환값 없음
     */
    public function test_timeout_failure_closes_run_even_while_lock_held(): void
    {
        $job = new ArchiveRangeJob('2026-01-01', '2026-01-02');
        $run = $this->createRunningRun(ArchiveRunType::Reaggregate, '2026-01-01');
        $lock = Cache::lock(JobSingleFlight::key(ArchiveRangeJob::class, $job->dispatchId), 600);
        $this->assertTrue($lock->get());

        $job->failed(new TimeoutExceededException('qa139 timeout'));

        $this->assertSame(ArchiveRunStatus::Failed, $run->fresh()->status);
        $lock->release();
    }

    /**
     * 긴 작업 연결·큐가 둘 다 등록돼 있으면 세 잡이 그 연결·큐로 간다.
     *
     * @scenario placement=long_registered
     *
     * @effects long_jobs_route_to_long_queue
     *
     * @return void 반환값 없음
     */
    public function test_long_jobs_route_to_long_connection_when_registered(): void
    {
        config([
            'g7_settings.core.drivers.queue_connections' => ['database', 'database-long'],
            'g7_settings.core.drivers.queue_names' => ['database' => ['default'], 'database-long' => ['long']],
        ]);

        foreach ([
            new ArchiveRangeJob('2026-01-01', '2026-01-02'),
            new AnonymizeLedgerJob(1, false),
            new RunPrivacyManualActionJob(1),
        ] as $job) {
            $this->assertSame('database-long', $job->connection, $job::class);
            $this->assertSame('long', $job->queue, $job::class);
        }
    }

    /**
     * 긴 작업 큐가 등록되지 않았으면 기본 연결·큐에 남는다.
     *
     * @scenario placement=long_not_registered
     *
     * @effects long_jobs_stay_on_default
     *
     * @return void 반환값 없음
     */
    public function test_long_jobs_stay_on_default_when_long_not_registered(): void
    {
        config([
            'g7_settings.core.drivers.queue_connections' => ['database', 'database-long'],
            'g7_settings.core.drivers.queue_names' => ['database' => ['default'], 'database-long' => ['x']],
        ]);

        foreach ([
            new ArchiveRangeJob('2026-01-01', '2026-01-02'),
            new AnonymizeLedgerJob(1, false),
            new RunPrivacyManualActionJob(1),
        ] as $job) {
            $this->assertNull($job->connection, $job::class);
            $this->assertNull($job->queue, $job::class);
        }
    }

    /**
     * 업데이트 전에 쌓인 옛 payload(dispatchId 없음)는 단일 실행 가드 없이 종전처럼 돈다.
     *
     * @scenario job=archive_range, event=legacy
     *
     * @effects legacy_payload_runs
     *
     * @return void 반환값 없음
     */
    public function test_legacy_payload_without_dispatch_id_runs_without_single_flight(): void
    {
        $job = new ArchiveRangeJob('2026-01-01', '2026-01-02');
        $job->dispatchId = '';
        $legacy = unserialize(serialize($job));

        $service = Mockery::mock(ArchiveService::class);
        $service->shouldReceive('archiveRange')->once()->andReturn([]);

        $legacy->handle($service);

        $this->assertSame('', $legacy->dispatchId);
    }

    /**
     * 지금 예약된 잡을 만료된 예약으로 돌리고 같은 프로세스에서 워커를 한 번 더 돌립니다(= 다른 워커가 다시 집음).
     *
     * @return void 반환값 없음
     */
    private function rereserveAndRunAnotherWorker(): void
    {
        DB::table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => now()->subSeconds(5000)->getTimestamp()]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--memory' => 4096, '--sleep' => 0]);
    }

    /**
     * database 큐를 빌 때까지 돌립니다.
     *
     * @return void 반환값 없음
     */
    private function runWorker(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096, '--sleep' => 0]);
    }

    /**
     * 잡이 자기 몫으로 찾을 「실행 중」 이력을 만듭니다.
     *
     * @param  ArchiveRunType  $type  실행 유형
     * @param  string  $day  기간 시작일 (Y-m-d)
     * @return AnalyticsArchiveRun 실행 이력
     */
    private function createRunningRun(ArchiveRunType $type, string $day): AnalyticsArchiveRun
    {
        [$start, $end] = AnalyticsClock::dayRangeUtc($day);

        return AnalyticsArchiveRun::create([
            'run_type' => $type->value,
            'period_start' => $start,
            'period_end' => $end,
            'trigger' => 'manual',
            'status' => ArchiveRunStatus::Running->value,
            'started_at' => AnalyticsClock::now()->addSecond(),
            'triggered_by' => null,
        ]);
    }
}
