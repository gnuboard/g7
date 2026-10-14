<?php

namespace Tests\Unit\Services\Queue;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\Analytics\QueueStatsRepositoryInterface;
use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 큐 워커 레지스트리 테스트 (#139)
 *
 * 운영자가 등록한 연결·큐의 해석, 하트비트 기록·스로틀, 상태 보고서 모양을 검증한다.
 */
class QueueWorkerRegistryTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 프로세스 하트비트 스로틀을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        QueueWorkerRegistry::resetProcessState();
    }

    /**
     * 등록이 비어 있으면 기본 연결의 default 큐 하나로 해석한다.
     *
     * @scenario driver=database, registration=empty
     *
     * @effects default_registry_resolved
     *
     * @return void 반환값 없음
     */
    public function test_default_registry_is_default_queue_on_default_connection(): void
    {
        $this->registry(['database'], [], []);

        $this->assertSame([['connection' => 'database', 'queue' => 'default']], $this->make()->entries());
        $this->assertTrue($this->make()->isConnectionRegistered('database'));
    }

    /**
     * 정의되지 않았거나 워커가 없는(sync) 연결은 목록에서 빠진다.
     *
     * @scenario driver=database, registration=invalid_entries
     *
     * @effects invalid_entries_dropped
     *
     * @return void 반환값 없음
     */
    public function test_entries_with_unknown_or_sync_connection_are_dropped(): void
    {
        $this->registry('database', ['database', 'sync', 'nope', 'database'], ['database' => ['default', 'mail']]);

        $this->assertSame(['database'], $this->make()->registeredConnections());
        $this->assertSame([
            ['connection' => 'database', 'queue' => 'default'],
            ['connection' => 'database', 'queue' => 'mail'],
        ], $this->make()->entries());
        $this->assertNotContains('sync', array_column($this->make()->registrableConnections(), 'name'));
        $this->assertContains('database-long', array_column($this->make()->registrableConnections(), 'name'));
    }

    /**
     * 등록된 연결·큐 쌍은 그대로 해석한다.
     *
     * @scenario driver=database, registration=registered
     *
     * @effects registered_pair_honored
     *
     * @return void 반환값 없음
     */
    public function test_resolve_honors_registered_pair(): void
    {
        $this->registry('database', ['database', 'redis'], ['redis' => ['high']]);

        $this->assertSame(['connection' => 'redis', 'queue' => 'high', 'fallback' => []], $this->make()->resolve('redis', 'high'));
    }

    /**
     * 등록되지 않은 큐는 그 연결의 기본 큐(null)로 바꾼다.
     *
     * @scenario driver=database, registration=queue_unregistered
     *
     * @effects unregistered_queue_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_resolve_falls_back_for_unregistered_queue(): void
    {
        $this->registry('database', ['database'], ['database' => ['default']]);

        $this->assertSame(['connection' => null, 'queue' => null, 'fallback' => ['queue_unregistered']], $this->make()->resolve(null, 'nope'));
    }

    /**
     * 등록되지 않은 연결은 기본 연결(null)로 바꾼다.
     *
     * @scenario driver=database, registration=connection_unregistered
     *
     * @effects unregistered_connection_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_resolve_falls_back_for_unregistered_connection(): void
    {
        $this->registry('database', ['database'], ['database' => ['default']]);

        $this->assertSame(['connection' => null, 'queue' => 'default', 'fallback' => ['connection_unregistered']], $this->make()->resolve('redis', 'default'));
    }

    /**
     * 긴 작업 배치는 「{기본}-long」 연결과 long 큐가 둘 다 등록됐을 때만 — 기본 연결이 sync 면 없다.
     *
     * @scenario driver=database, registration=long
     *
     * @effects long_placement_requires_both
     *
     * @return void 반환값 없음
     */
    public function test_long_placement_requires_registered_long_pair_for_current_default(): void
    {
        $this->registry('database', ['database', 'database-long'], ['database-long' => ['long']]);
        $this->assertSame(['connection' => 'database-long', 'queue' => 'long'], $this->make()->longPlacement());

        $this->registry('database', ['database', 'database-long'], ['database-long' => ['x']]);
        $this->assertNull($this->make()->longPlacement());

        // 키가 없으면 *-long 연결의 기본 큐는 long
        $this->registry('database', ['database', 'database-long'], []);
        $this->assertSame(['connection' => 'database-long', 'queue' => 'long'], $this->make()->longPlacement());

        $this->registry('redis', ['redis', 'redis-long'], []);
        $this->assertSame(['connection' => 'redis-long', 'queue' => 'long'], $this->make()->longPlacement());

        $this->registry('database', ['database'], []);
        $this->assertNull($this->make()->longPlacement());

        $this->registry('sync', ['database', 'database-long'], []);
        $this->assertNull($this->make()->longPlacement());
    }

    /**
     * 하트비트는 프로세스당 30초에 한 번만 쓴다.
     *
     * @scenario heartbeat=throttled
     *
     * @effects heartbeat_recorded
     *
     * @return void 반환값 없음
     */
    public function test_heartbeat_is_throttled_per_process(): void
    {
        $this->registry('database', ['database'], []);
        $this->travelTo('2026-10-13 10:00:00');
        $this->make()->recordHeartbeat('database', 'default');
        $first = $this->make()->lastHeartbeat('database', 'default');

        $this->travelTo('2026-10-13 10:00:20');
        $this->make()->recordHeartbeat('database', 'default');
        $this->assertSame($first, $this->make()->lastHeartbeat('database', 'default'));

        $this->travelTo('2026-10-13 10:00:31');
        $this->make()->recordHeartbeat('database', 'default');
        $this->assertSame($first + 31, $this->make()->lastHeartbeat('database', 'default'));
    }

    /**
     * 쉼표 큐 목록은 큐마다 따로 기록한다.
     *
     * @scenario heartbeat=multi_queue
     *
     * @effects heartbeat_recorded
     *
     * @return void 반환값 없음
     */
    public function test_heartbeat_splits_comma_separated_queues(): void
    {
        $this->registry('database', ['database'], []);
        $this->make()->recordHeartbeat('database', 'high, default,low');

        foreach (['high', 'default', 'low'] as $queue) {
            $this->assertNotNull($this->make()->lastHeartbeat('database', $queue), $queue);
        }
        $this->assertNull($this->make()->lastHeartbeat('database', 'other'));
    }

    /**
     * 캐시가 실패해도 하트비트는 예외를 올리지 않는다 — 워커를 죽이지 않는다.
     *
     * @scenario heartbeat=cache_failure
     *
     * @effects heartbeat_never_throws
     *
     * @return void 반환값 없음
     */
    public function test_heartbeat_failure_never_throws(): void
    {
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('put')->andThrow(new RuntimeException('cache down'));
        $cache->shouldReceive('get')->andThrow(new RuntimeException('cache down'));
        $registry = new QueueWorkerRegistry(app(QueueStatsRepositoryInterface::class), $cache);

        $registry->recordHeartbeat('database', 'default');
        $registry->recordTimeoutClamp(['connection' => 'database', 'queue' => 'default']);

        $this->assertNull($registry->lastHeartbeat('database', 'default'));
    }

    /**
     * 5분 넘게 신호가 없으면 no_heartbeat 경고가 붙는다.
     *
     * @scenario heartbeat=stale
     *
     * @effects status_flags_missing_worker
     *
     * @return void 반환값 없음
     */
    public function test_status_report_flags_registered_queue_without_recent_heartbeat(): void
    {
        $this->registry('database', ['database'], []);
        $this->travelTo('2026-10-13 10:00:00');
        $this->make()->recordHeartbeat('database', 'default');
        $this->assertSame([], $this->make()->statusReport()['rows'][0]['warnings']);

        $this->travelTo('2026-10-13 10:05:01');
        $row = $this->make()->statusReport()['rows'][0];
        $this->assertSame(['no_heartbeat'], $row['warnings']);
        $this->assertNotNull($row['last_heartbeat_at']);
    }

    /**
     * 대기 건수는 database 드라이버만 큐별로 센다 — redis 는 측정 불가.
     *
     * @scenario driver=database_and_redis
     *
     * @effects pending_counted_per_queue
     *
     * @return void 반환값 없음
     */
    public function test_status_report_counts_pending_per_queue_on_database_only(): void
    {
        $this->registry('database', ['database', 'redis'], ['database' => ['default', 'qa139'], 'redis' => ['default']]);
        DB::table('jobs')->insert([
            ['queue' => 'qa139', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'qa139', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
        ]);

        $rows = collect($this->make()->statusReport()['rows'])->keyBy(fn ($r) => $r['connection'].':'.$r['queue']);

        $this->assertSame(2, $rows['database:qa139']['pending']);
        $this->assertSame(['pending_without_worker'], $rows['database:qa139']['warnings']);
        $this->assertSame(0, $rows['database:default']['pending']);
        $this->assertTrue($rows['database:default']['pending_measurable']);
        $this->assertNull($rows['redis:default']['pending']);
        $this->assertFalse($rows['redis:default']['pending_measurable']);
    }

    /**
     * 상태 보고서에 timeout 축소·폴백 카운터가 실린다.
     *
     * @scenario placement=counters
     *
     * @effects placement_counters_reported
     *
     * @return void 반환값 없음
     */
    public function test_status_report_includes_clamp_and_fallback_counters(): void
    {
        $this->registry('database', ['database'], []);
        $registry = $this->make();
        $registry->recordTimeoutClamp(['connection' => 'database', 'queue' => 'default', 'requested' => 90, 'applied' => 80]);
        $registry->recordTimeoutClamp(['connection' => 'database', 'queue' => 'default', 'requested' => 120, 'applied' => 80]);
        $registry->recordFallback(['reasons' => ['queue_unregistered']]);

        $report = $this->make()->statusReport();

        $this->assertSame(2, $report['placement']['timeout_clamped']['count']);
        $this->assertSame(120, $report['placement']['timeout_clamped']['last']['requested']);
        $this->assertSame(1, $report['placement']['fallback']['count']);
        $this->assertSame(2, $report['rows'][0]['clamped_timeout_count']);
    }

    /**
     * Supervisor 명령은 연결별이고, 긴 작업 연결에는 --timeout=3600 이 붙는다.
     *
     * @scenario driver=database, registration=long
     *
     * @effects supervisor_commands_listed
     *
     * @return void 반환값 없음
     */
    public function test_status_report_commands_include_long_timeout(): void
    {
        $this->registry('database', ['database', 'database-long'], ['database' => ['default', 'mail']]);

        $commands = collect($this->make()->statusReport()['commands'])->keyBy('connection');

        $this->assertSame('php artisan queue:work database --queue=default,mail --sleep=3 --tries=3 --max-time=3600', $commands['database']['command']);
        $this->assertSame('php artisan queue:work database-long --queue=long --sleep=3 --tries=3 --max-time=3600 --timeout=3600', $commands['database-long']['command']);
        $this->assertSame('registered', $this->make()->statusReport()['long_worker']['status']);
    }

    /**
     * 큐 드라이버와 등록값을 설정합니다.
     *
     * @param  string|array<int, string>  $driver  큐 드라이버(기본 연결) — 배열이면 첫 원소
     * @param  array<int, string>  $connections  등록 연결
     * @param  array<string, array<int, string>>  $names  연결별 큐
     * @return void 반환값 없음
     */
    private function registry(string|array $driver, array $connections, array $names): void
    {
        config([
            'queue.default' => is_array($driver) ? $driver[0] : $driver,
            'g7_settings.core.drivers.queue_connections' => $connections,
            'g7_settings.core.drivers.queue_names' => $names,
        ]);
    }

    /**
     * 레지스트리를 만듭니다.
     *
     * @return QueueWorkerRegistry 레지스트리
     */
    private function make(): QueueWorkerRegistry
    {
        return app(QueueWorkerRegistry::class);
    }
}
