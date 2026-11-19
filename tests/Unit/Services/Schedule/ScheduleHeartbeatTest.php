<?php

namespace Tests\Unit\Services\Schedule;

use App\Contracts\Extension\CacheInterface;
use App\Services\Schedule\ScheduleHeartbeat;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 스케줄러 생존 신호 — 틱 기록·조회, 캐시 장애 무해
 */
class ScheduleHeartbeatTest extends TestCase
{
    /**
     * 테스트 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * beat() 가 지금 시각을 남기고 lastTick() 이 그대로 돌려준다.
     *
     * @scenario trigger=scheduled
     *
     * @effects heartbeat_records_last_tick
     *
     * @return void 반환값 없음
     */
    public function test_beat_then_last_tick(): void
    {
        Carbon::setTestNow('2026-10-16 03:04:05');
        $heartbeat = app(ScheduleHeartbeat::class);

        $heartbeat->beat();

        $this->assertSame('2026-10-16 03:04:05', $heartbeat->lastTick()?->format('Y-m-d H:i:s'));
    }

    /**
     * 신호가 없으면 null, 캐시가 실패해도 예외를 내지 않는다(스케줄러를 멈추지 않는다).
     *
     * @scenario trigger=scheduled
     *
     * @effects heartbeat_never_breaks_scheduler
     *
     * @return void 반환값 없음
     */
    public function test_missing_signal_and_cache_failure_are_harmless(): void
    {
        $empty = Mockery::mock(CacheInterface::class);
        $empty->shouldReceive('get')->with(ScheduleHeartbeat::CACHE_KEY)->andReturnNull();
        $this->assertNull((new ScheduleHeartbeat($empty))->lastTick());

        $broken = Mockery::mock(CacheInterface::class);
        $broken->shouldReceive('put')->andThrow(new RuntimeException('cache down'));
        $broken->shouldReceive('get')->andThrow(new RuntimeException('cache down'));
        $heartbeat = new ScheduleHeartbeat($broken);

        $heartbeat->beat();
        $this->assertNull($heartbeat->lastTick());
    }
}
