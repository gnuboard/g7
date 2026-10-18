<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

use App\Models\Schedule;
use App\Services\Schedule\ScheduleLookupService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Throwable;

/**
 * 환율 정기 갱신 시각을 코어 스케줄 행에서 읽습니다.
 *
 * 갱신 시각은 플러그인 설정이 아니라 관리자 > 스케줄 관리의 `update-rates` 행이 정한다(코어 7.2.0).
 * 운영자가 그 행의 cron 식을 바꾸면 상태 화면의 다음 실행 시각과 재시도 시작 시각이 함께 따라간다.
 * 행을 찾지 못하면(동기화 전·스케줄 표 없음) 선언 기본값(한국시간 11:30)으로 계산한다.
 */
class UpdateScheduleResolver
{
    /** 정기 갱신 스케줄의 기계 식별자 (plugin.php getSchedules() 와 같은 값) */
    public const SCHEDULE_NAME = 'update-rates';

    /** 선언 기본 cron 식 — 한국시간 11:30 (수출입은행 고시 이후) */
    public const DEFAULT_EXPRESSION = '30 11 * * *';

    /** 선언 시간대 — 은행 고시 기준 */
    public const TIMEZONE = 'Asia/Seoul';

    /** 실행 요일 필터로 다음 시각을 찾을 때 넘겨 볼 최대 후보 수 */
    private const MAX_CANDIDATES = 64;

    /**
     * @param  ScheduleLookupService  $lookup  코어 스케줄 조회 서비스
     */
    public function __construct(private ScheduleLookupService $lookup) {}

    /**
     * 정기 갱신 스케줄 행을 찾습니다.
     *
     * @return Schedule|null 스케줄 행 (없거나 스케줄 표를 읽을 수 없으면 null)
     */
    public function schedule(): ?Schedule
    {
        try {
            return $this->lookup->findOwned('plugin', 'sirsoft-koreaexim', self::SCHEDULE_NAME);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 주어진 시각이 속한 한국 날짜의 첫 정기 갱신 시각을 반환합니다.
     *
     * @param  CarbonInterface  $now  기준 시각
     * @param  Schedule|null  $schedule  미리 조회한 행 (null 이면 기본 식)
     * @return CarbonImmutable|null 그날의 갱신 시각(한국시간), 그날 일정이 없으면 null
     */
    public function todayRunAt(CarbonInterface $now, ?Schedule $schedule = null): ?CarbonImmutable
    {
        $day = CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE)->startOfDay();
        $first = $this->next($day->subSecond(), $schedule);

        return $first !== null && $first->isSameDay($day) ? $first : null;
    }

    /**
     * 기준 시각 이후 실행 요일에 해당하는 다음 정기 갱신 시각을 반환합니다.
     *
     * @param  CarbonInterface  $from  기준 시각
     * @param  array<int, int>  $runDays  실행 요일 (ISO 1=월 … 7=일, 한국 날짜 기준)
     * @param  Schedule|null  $schedule  미리 조회한 행 (null 이면 기본 식)
     * @return CarbonImmutable|null 다음 갱신 시각(한국시간), 범위 안에 없으면 null
     */
    public function nextRunAt(CarbonInterface $from, array $runDays, ?Schedule $schedule = null): ?CarbonImmutable
    {
        $days = array_map('intval', $runDays);
        $cursor = CarbonImmutable::instance($from);
        for ($i = 0; $i < self::MAX_CANDIDATES; $i++) {
            $candidate = $this->next($cursor, $schedule);
            if ($candidate === null) {
                return null;
            }
            if (in_array($candidate->dayOfWeekIso, $days, true)) {
                return $candidate;
            }
            $cursor = $candidate;
        }

        return null;
    }

    /**
     * 기준 시각 바로 다음 일정 시각을 계산합니다 (행이 있으면 코어 계산, 없으면 기본 식).
     *
     * @param  CarbonInterface  $from  기준 시각
     * @param  Schedule|null  $schedule  스케줄 행
     * @return CarbonImmutable|null 다음 시각(한국시간), 식이 잘못됐으면 null
     */
    private function next(CarbonInterface $from, ?Schedule $schedule): ?CarbonImmutable
    {
        if ($schedule !== null) {
            $next = $this->lookup->nextRunAt($schedule, $from);

            return $next === null ? null : CarbonImmutable::instance($next)->setTimezone(self::TIMEZONE);
        }

        try {
            $next = (new CronExpression(self::DEFAULT_EXPRESSION))
                ->getNextRunDate(CarbonImmutable::instance($from)->toDateTimeImmutable(), 0, false, self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return CarbonImmutable::instance($next)->setTimezone(self::TIMEZONE);
    }
}
