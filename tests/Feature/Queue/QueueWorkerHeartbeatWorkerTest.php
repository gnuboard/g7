<?php

namespace Tests\Feature\Queue;

use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 실제 큐 워커가 자기 연결·큐의 생존 신호(하트비트)를 남긴다 (#139)
 *
 * 워커 루프(`Looping`)와 잡 시작(`JobProcessing` — `--once` 워커는 루프 이벤트가 없다) 리스너가 EventServiceProvider 에
 * 붙어 있는지, 그리고 그 신호를 드라이버 탭 상태가 읽는지 실워커로 확인한다.
 */
class QueueWorkerHeartbeatWorkerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 큐를 database 로 두고 하트비트 스로틀을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        QueueWorkerRegistry::resetProcessState();
    }

    /**
     * 워커 루프가 자기가 보는 큐(쉼표 목록)마다 신호를 남긴다.
     *
     * @scenario worker=loop
     *
     * @effects worker_heartbeat_recorded
     *
     * @return void 반환값 없음
     */
    public function test_real_worker_loop_records_heartbeat_for_its_queue(): void
    {
        $registry = app(QueueWorkerRegistry::class);
        $this->assertNull($registry->lastHeartbeat('database', 'qa139hb'));

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'qa139hb,qa139hb2', '--stop-when-empty' => true, '--memory' => 4096, '--sleep' => 0]);

        $this->assertNotNull($registry->lastHeartbeat('database', 'qa139hb'));
        $this->assertNotNull($registry->lastHeartbeat('database', 'qa139hb2'));
    }

    /**
     * --once 워커는 루프 이벤트가 없어도 잡 시작 이벤트로 신호를 남긴다.
     *
     * @scenario worker=once
     *
     * @effects worker_heartbeat_recorded
     *
     * @return void 반환값 없음
     */
    public function test_once_worker_records_heartbeat_via_job_processing(): void
    {
        $registry = app(QueueWorkerRegistry::class);
        DB::table('jobs')->insert([
            'queue' => 'qa139once',
            'payload' => json_encode([
                'uuid' => 'qa139-once',
                'displayName' => 'qa139-probe',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 1,
                'data' => ['commandName' => 'qa139', 'command' => serialize(new QueueWorkerHeartbeatNoop)],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'qa139once', '--once' => true, '--memory' => 4096, '--sleep' => 0]);

        $this->assertNotNull($registry->lastHeartbeat('database', 'qa139once'));
        $this->assertSame(0, DB::table('jobs')->where('queue', 'qa139once')->count(), '잡이 처리되지 않았다');
    }
}

/**
 * 아무 일도 하지 않는 큐 잡 (하트비트 확인용)
 */
class QueueWorkerHeartbeatNoop implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * 아무 일도 하지 않습니다.
     *
     * @return void 반환값 없음
     */
    public function handle(): void {}
}
