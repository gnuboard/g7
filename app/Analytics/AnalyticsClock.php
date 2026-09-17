<?php

namespace App\Analytics;

use Illuminate\Support\Carbon;

/**
 * 방문자 통계 시각 기준
 *
 * 원장은 UTC 로 적고 집계와 화면은 사이트 표준시로 읽습니다. 두 기준이 섞이면
 * 한국 새벽 2시 방문이 어제로 집계되는 식으로 「오늘」의 경계가 어긋납니다.
 * 날짜·시간 경계를 만드는 모든 지점이 이 클래스를 거칩니다.
 */
class AnalyticsClock
{
    /**
     * 사이트 표준시를 반환합니다.
     *
     * 운영자가 일반 설정에서 정한 값이 우선이고, 없으면 앱 기본값을 씁니다.
     *
     * @return string 표준시 이름 (예: Asia/Seoul)
     */
    public static function siteTimezone(): string
    {
        $timezone = g7_core_settings('general.timezone', config('app.timezone', 'UTC'));

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * 현재 시각을 UTC 로 반환합니다.
     *
     * @return Carbon 현재 시각 (UTC)
     */
    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * 사이트 표준시 기준 오늘 일자를 반환합니다.
     *
     * @return string 일자 (Y-m-d)
     */
    public static function today(): string
    {
        return Carbon::now(self::siteTimezone())->toDateString();
    }

    /**
     * 주어진 시각이 사이트 표준시로 어느 날인지 반환합니다.
     *
     * @param  Carbon  $moment  대상 시각
     * @return string 일자 (Y-m-d)
     */
    public static function dateOf(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::siteTimezone())->toDateString();
    }

    /**
     * 주어진 시각이 사이트 표준시로 어느 시간대인지 반환합니다.
     *
     * 시간 집계는 이 값을 그대로 저장합니다 — UTC 로 저장하고 화면에서 바꾸면
     * 시간대별 이용 패턴을 시(hour)로 묶을 때 사이트 기준과 어긋납니다.
     *
     * @param  Carbon  $moment  대상 시각
     * @return string 시각 (Y-m-d H:00:00)
     */
    public static function hourStartOf(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::siteTimezone())->startOfHour()->format('Y-m-d H:i:s');
    }

    /**
     * 사이트 표준시 일자의 하루 구간을 UTC 로 반환합니다.
     *
     * 원장은 UTC 로 적혀 있으므로 「그 날의 방문」을 고르려면 경계를 UTC 로 바꿔야
     * 합니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return array{0: Carbon, 1: Carbon} 시작(포함)과 끝(미포함) 시각 (UTC)
     */
    public static function dayRangeUtc(string $date): array
    {
        $timezone = self::siteTimezone();
        $start = Carbon::parse($date.' 00:00:00', $timezone)->setTimezone('UTC');
        $end = Carbon::parse($date.' 00:00:00', $timezone)->addDay()->setTimezone('UTC');

        return [$start, $end];
    }

    /**
     * 방문이 아직 이어지는 것으로 볼 기준 시각을 반환합니다.
     *
     * @param  int  $timeoutMinutes  방문 이어짐 판정 시간 (분)
     * @return Carbon 이 시각 이후 활동이 있으면 같은 방문 (UTC)
     */
    public static function visitWindowStart(int $timeoutMinutes): Carbon
    {
        return self::now()->subMinutes(max(1, $timeoutMinutes));
    }
}
