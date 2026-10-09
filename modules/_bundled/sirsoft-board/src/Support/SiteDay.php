<?php

namespace Modules\Sirsoft\Board\Support;

use App\Helpers\TimezoneHelper;
use Carbon\CarbonImmutable;

/**
 * 게시판의 「하루」 경계 — 사이트 표준시 기준.
 *
 * 저장값과 앱 시간대(`app.timezone`)는 UTC 다. 일일 신고 한도·대시보드 일 집계처럼 하루 단위로
 * 세는 기능이 앱 시간대로 자르면 한국 사이트에서는 오전 9시에 하루가 바뀐다. 운영자가 정한
 * 사이트 표준시(`general.timezone` → `app.default_user_timezone`, 스케줄러의 `app.schedule_timezone`
 * 과 같은 값)로 자르고, DB 비교는 UTC 범위로 바꿔서 한다.
 */
final class SiteDay
{
    /**
     * 사이트 표준시 이름을 반환합니다.
     *
     * @return string 표준시 이름 (예: Asia/Seoul)
     */
    public static function timezone(): string
    {
        $timezone = TimezoneHelper::getSiteTimezone();

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * 사이트 표준시로 오늘 0시를 반환합니다 (사이트 표준시 값).
     *
     * @return CarbonImmutable 오늘 0시
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * 사이트 표준시의 하루(Y-m-d)를 UTC 반열린 구간 [시작, 끝)으로 바꿉니다.
     *
     * @param  string  $date  사이트 표준시 날짜 (Y-m-d)
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC 시작(포함)·끝(제외)
     */
    public static function utcRange(string $date): array
    {
        $start = CarbonImmutable::parse($date, self::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }
}
