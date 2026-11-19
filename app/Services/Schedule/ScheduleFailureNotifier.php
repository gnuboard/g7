<?php

namespace App\Services\Schedule;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ScheduleTriggerType;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 예약 작업 실패 알림 판정
 *
 * 알림 정의 `schedule_failed` 는 `core.schedule.run_failed` 훅을 구독한다. 실행기는 수동·예약을 가리지 않고
 * 실패마다 그 훅을 발화하므로, 실제로 보낼지는 알림 데이터 리스너가 이 판정을 물어 정한다(아니면 context.skip).
 *
 * - 스케줄의 「실패 시 알림」이 꺼져 있으면 보내지 않는다.
 * - 운영자가 화면에서 「즉시 실행」한 실패는 보내지 않는다 — 결과를 이미 화면에서 본다.
 * - 같은 스케줄은 사이트 시간대 기준 하루 1회만 보낸다. 캐시 장애면 보낸다(놓치는 쪽이 더 해롭다).
 */
class ScheduleFailureNotifier
{
    /** 하루 1회 스로틀 캐시 키 접두사 — `schedule:failure_notified:{id}:{Ymd}` */
    public const THROTTLE_PREFIX = 'schedule:failure_notified:';

    /** 스로틀 보관 시간(초) */
    private const TTL_SECONDS = 86400;

    /**
     * @param  CacheInterface  $cache  스로틀 저장소
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 이 실패를 알릴지 판정하고, 알린다면 오늘 몫을 차지합니다.
     *
     * @param  Schedule  $schedule  실패한 스케줄
     * @param  ScheduleHistory|null  $history  실패 이력
     * @return bool 알림을 보내야 하면 true
     */
    public function shouldNotify(Schedule $schedule, ?ScheduleHistory $history): bool
    {
        if (! $schedule->notify_on_failure) {
            return false;
        }

        if ($history?->trigger_type === ScheduleTriggerType::Manual) {
            return false;
        }

        return $this->claim($schedule);
    }

    /**
     * 오늘 이 스케줄의 실패 알림 몫을 차지합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return bool 오늘 처음이면 true, 이미 보냈으면 false
     */
    public function claim(Schedule $schedule): bool
    {
        $key = $this->throttleKey($schedule);

        try {
            if ($this->cache->has($key)) {
                return false;
            }

            $this->cache->put($key, Carbon::now()->getTimestamp(), self::TTL_SECONDS);
        } catch (Throwable) {
            return true;
        }

        return true;
    }

    /**
     * 스로틀 캐시 키를 만듭니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  CarbonInterface|null  $at  기준 시각 (null 이면 지금)
     * @return string `schedule:failure_notified:{id}:{사이트 시간대 Ymd}`
     */
    public function throttleKey(Schedule $schedule, ?CarbonInterface $at = null): string
    {
        $timezone = (string) (config('app.schedule_timezone') ?? config('app.timezone', 'UTC'));
        $day = Carbon::instance($at ?? Carbon::now())->setTimezone($timezone)->format('Ymd');

        return self::THROTTLE_PREFIX.$schedule->id.':'.$day;
    }
}
