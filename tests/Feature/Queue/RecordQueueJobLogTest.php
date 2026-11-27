<?php

namespace Tests\Feature\Queue;

use App\Listeners\Queue\RecordQueueJobLog;
use App\Models\QueueJobLog;
use App\Services\JobQueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fixtures\Queue\QueueProbeJob;
use Tests\TestCase;

/**
 * 큐 작업 처리 기록 리스너 테스트
 *
 * sync 연결은 요청 종료까지 버퍼에 모아 한 번에 넣고, 워커 연결은 한 건씩 바로 넣는다. 재시도 대기(release)는
 * 처리가 끝난 것이 아니므로 남기지 않는다. 트랜잭션으로 격리한다.
 */
class RecordQueueJobLogTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 큐 표·기록 표를 비우고 탐침 작업 상태를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        QueueProbeJob::reset();
        config([
            'g7_settings.core.drivers.queue_job_log_enabled' => true,
            'g7_settings.core.drivers.queue_connections' => [],
            'g7_settings.core.drivers.queue_names' => [],
            'queue.job_log.excluded_jobs' => [],
        ]);
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        QueueJobLog::query()->delete();
    }

    /**
     * 탐침 작업 상태를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        QueueProbeJob::reset();

        parent::tearDown();
    }

    /**
     * 처리 기록 리스너(싱글턴)를 돌려줍니다.
     *
     * @return RecordQueueJobLog 리스너
     */
    private function recorder(): RecordQueueJobLog
    {
        return app(RecordQueueJobLog::class);
    }

    /**
     * database 큐 워커로 한 건을 처리합니다.
     *
     * @return void 반환값 없음
     */
    private function workOnce(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0]);
    }

    /**
     * sync 연결 성공은 버퍼에 모였다가 flush 때 processed 1행으로 남는다(시작·종료·소요 시간 포함).
     *
     * @scenario connection=sync, result=processed
     *
     * @effects sync_records_buffered_until_flush, processed_row_has_timing
     *
     * @return void 반환값 없음
     */
    public function test_sync_성공은_버퍼에_모였다가_한번에_기록된다(): void
    {
        config(['queue.default' => 'sync']);

        QueueProbeJob::dispatch('sync-ok');

        $this->assertSame(1, QueueProbeJob::$handled);
        $this->assertSame(0, QueueJobLog::query()->count(), 'sync 기록은 요청 종료 전에는 넣지 않는다');

        $this->recorder()->flush();

        $log = QueueJobLog::query()->sole();
        $this->assertSame('processed', $log->status->value);
        $this->assertSame('sync', $log->connection);
        $this->assertSame(QueueProbeJob::class, $log->job_class);
        $this->assertSame(QueueProbeJob::class, $log->display_name);
        $this->assertNotNull($log->uuid);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->finished_at);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        $this->assertNull($log->exception_class);
    }

    /**
     * 요청 종료(terminate) 콜백이 버퍼를 넣는다.
     *
     * @scenario connection=sync, flush=terminating
     *
     * @effects sync_records_flushed_on_terminate
     *
     * @return void 반환값 없음
     */
    public function test_요청_종료시_버퍼가_기록된다(): void
    {
        config(['queue.default' => 'sync']);

        QueueProbeJob::dispatch('sync-terminate');
        $this->app->terminate();

        $this->assertSame(1, QueueJobLog::query()->count());
    }

    /**
     * 버퍼가 상한에 이르면 요청 종료를 기다리지 않고 넣는다.
     *
     * @scenario connection=sync, flush=buffer_full
     *
     * @effects sync_buffer_flushes_at_limit
     *
     * @return void 반환값 없음
     */
    public function test_버퍼가_상한에_이르면_바로_기록된다(): void
    {
        config(['queue.default' => 'sync']);

        for ($i = 0; $i < RecordQueueJobLog::BUFFER_FLUSH_SIZE; $i++) {
            QueueProbeJob::dispatch('bulk-'.$i);
        }

        $this->assertSame(RecordQueueJobLog::BUFFER_FLUSH_SIZE, QueueJobLog::query()->count());
    }

    /**
     * sync 연결 실패는 failed 1행과 예외 클래스·메시지로 남는다.
     *
     * @scenario connection=sync, result=failed
     *
     * @effects failed_row_has_exception
     *
     * @return void 반환값 없음
     */
    public function test_sync_실패는_예외와_함께_기록된다(): void
    {
        config(['queue.default' => 'sync']);
        QueueProbeJob::$shouldFail = true;

        try {
            QueueProbeJob::dispatch('sync-fail');
            $this->fail('sync 작업 예외가 호출자에게 전달돼야 한다');
        } catch (RuntimeException) {
            // sync 는 요청 안에서 예외를 다시 던진다
        }

        $this->recorder()->flush();

        $log = QueueJobLog::query()->sole();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertSame('probe failure: sync-fail', $log->exception_message);
    }

    /**
     * 재시도 대기(release)는 처리가 끝난 것이 아니므로 기록하지 않는다.
     *
     * @scenario connection=database, result=released
     *
     * @effects released_job_not_recorded
     *
     * @return void 반환값 없음
     */
    public function test_재시도_대기로_돌려보낸_작업은_기록하지_않는다(): void
    {
        config(['queue.default' => 'database']);
        QueueProbeJob::$released = true;

        QueueProbeJob::dispatch('released');
        $this->workOnce();

        $this->assertSame(1, QueueProbeJob::$handled);
        $this->assertSame(0, QueueJobLog::query()->count());
        $this->assertSame(1, DB::table('jobs')->count(), '돌려보낸 작업은 대기 표에 남는다');
    }

    /**
     * 설정에서 끄거나 제외 목록에 넣은 작업은 기록하지 않는다.
     *
     * @scenario switch=disabled|excluded
     *
     * @effects log_can_be_disabled, log_excluded_jobs
     *
     * @return void 반환값 없음
     */
    public function test_끄거나_제외한_작업은_기록하지_않는다(): void
    {
        config(['queue.default' => 'sync', 'g7_settings.core.drivers.queue_job_log_enabled' => false]);
        QueueProbeJob::dispatch('disabled');
        $this->recorder()->flush();
        $this->assertSame(0, QueueJobLog::query()->count());

        config(['g7_settings.core.drivers.queue_job_log_enabled' => true, 'queue.job_log.excluded_jobs' => [QueueProbeJob::class]]);
        QueueProbeJob::dispatch('excluded');
        $this->recorder()->flush();
        $this->assertSame(0, QueueJobLog::query()->count());

        $this->assertSame(2, QueueProbeJob::$handled, '기록을 끄거나 빼도 작업 처리는 그대로 돈다');
    }

    /**
     * 워커 연결: 실패 → failed 기록·failed_jobs → 재시도 → 처리 → processed 기록. 워커 기록은 바로 넣는다.
     *
     * @scenario connection=database, chain=fail_retry_process
     *
     * @effects worker_records_immediately, retry_then_processed_chain
     *
     * @return void 반환값 없음
     */
    public function test_워커에서_실패한_작업을_재시도하면_처리기록이_이어진다(): void
    {
        config(['queue.default' => 'database']);
        QueueProbeJob::$shouldFail = true;

        QueueProbeJob::dispatch('chain');
        $this->workOnce();

        $failed = QueueJobLog::query()->sole();
        $this->assertSame('failed', $failed->status->value);
        $this->assertSame('database', $failed->connection);
        $this->assertSame('default', $failed->queue);
        $uuid = DB::table('failed_jobs')->value('uuid');
        $this->assertSame($uuid, $failed->uuid, '처리 기록 UUID 는 실패 작업 UUID 와 같다');

        QueueProbeJob::$shouldFail = false;
        $result = app(JobQueueService::class)->retry($uuid);
        $this->assertTrue($result['ok']);
        $this->workOnce();

        $this->assertSame(2, QueueProbeJob::$handled);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $processed = QueueJobLog::query()->where('status', 'processed')->sole();
        $this->assertSame($uuid, $processed->uuid);
    }
}
