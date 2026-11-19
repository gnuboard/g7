<?php

namespace App\Services\Schedule;

use App\Contracts\Extension\CacheInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 스케줄러 생존 신호
 *
 * 등록기가 매분 `schedule:heartbeat` 이벤트로 beat() 를 부른다. 서버 크론이 `schedule:run` 을
 * 돌리고 있는지는 이 값이 갱신되는지로만 알 수 있다 — DB 행·실행 이력은 남기지 않는다.
 *
 * 다중 서버는 공유 캐시를 전제한다(onOneServer 와 같은 전제). 파일 캐시면 서버마다 따로 보인다.
 */
class ScheduleHeartbeat
{
    /** 마지막 틱 시각(unix ts) 캐시 키 */
    public const CACHE_KEY = 'schedule:heartbeat:last_tick';

    /** 보관 시간(초) — 하루 넘게 신호가 없으면 「한 번도 없음」과 같게 본다 */
    private const TTL_SECONDS = 86400;

    /**
     * @param  CacheInterface  $cache  생존 신호 저장소
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 지금 틱이 돌았다고 기록합니다.
     *
     * 스케줄러를 절대 멈추지 않는다 — 캐시가 실패해도 조용히 넘어간다.
     *
     * @return void 반환값 없음
     */
    public function beat(): void
    {
        try {
            $this->cache->put(self::CACHE_KEY, Carbon::now()->getTimestamp(), self::TTL_SECONDS);
        } catch (Throwable) {
            // 표시용 신호다 — 다른 예약 작업을 막지 않는다.
        }
    }

    /**
     * 마지막 틱 시각을 반환합니다.
     *
     * @return CarbonInterface|null 마지막 틱 시각 (신호가 없거나 캐시를 읽지 못하면 null)
     */
    public function lastTick(): ?CarbonInterface
    {
        try {
            $value = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }
}
