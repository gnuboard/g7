<?php

namespace Plugins\Sirsoft\Popup\Enums;

use Carbon\CarbonInterface;

/**
 * 팝업 노출 상태 — 저장하지 않고 조회 시점에 기간·사용 여부로 계산한다(cron 없음, #810 과 같은 규칙).
 *
 * 판정 순서: 비활성 > 예약(시작 전) > 종료(종료 후) > 노출중. 정렬 순위도 이 Enum 의 `rank()` 를 따른다.
 */
enum PopupStatus: string
{
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Ended = 'ended';
    case Inactive = 'inactive';

    /**
     * 사용 여부·기간으로 그 시각의 상태를 판정합니다 — PHP 쪽 판정의 유일한 규칙(SQL 쪽은 PopupRepository::applyStatusFilter).
     *
     * 경계: 시작 시각 정각부터 노출, 종료 시각 정각까지 노출(그 다음부터 종료).
     *
     * @param  bool  $isActive  사용 여부
     * @param  string  $displayPeriod  노출 기간 방식 (always, range)
     * @param  CarbonInterface|null  $startsAt  시작 일시 (UTC)
     * @param  CarbonInterface|null  $endsAt  종료 일시 (UTC)
     * @param  CarbonInterface  $now  기준 시각
     * @return self 노출 상태
     */
    public static function resolve(bool $isActive, string $displayPeriod, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt, CarbonInterface $now): self
    {
        if (! $isActive) {
            return self::Inactive;
        }

        if ($displayPeriod === DisplayPeriod::Range->value) {
            if ($startsAt !== null && $startsAt->gt($now)) {
                return self::Scheduled;
            }
            if ($endsAt !== null && $endsAt->lt($now)) {
                return self::Ended;
            }
        }

        return self::Active;
    }

    /**
     * 상태 정렬 순위를 반환합니다(작을수록 앞).
     *
     * @return int 정렬 순위
     */
    public function rank(): int
    {
        return match ($this) {
            self::Active => 1,
            self::Scheduled => 2,
            self::Ended => 3,
            self::Inactive => 4,
        };
    }

    /**
     * 상태 값 목록을 반환합니다.
     *
     * @return array<int, string> 상태 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
