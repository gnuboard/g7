<?php

namespace App\Analytics;

use App\Helpers\TimezoneHelper;
use Illuminate\Support\Carbon;
use Throwable;

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
        // `TimezoneHelper::getSiteTimezone()` 과 같은 뜻이지만 읽는 자리가 다르다 — 이쪽은 저장된
        // 설정을 그때그때 읽고, 헬퍼는 부팅 때 `SettingsServiceProvider` 가 심은 config 스냅샷을
        // 읽는다. 집계는 설정을 바꾼 직후에도 새 경계를 써야 하므로 여기서는 설정을 직접 읽는다
        // (헬퍼로 합쳤다가 런타임에 시간대를 바꾸는 집계 테스트 4건이 깨져 되돌렸다 — #682 S3 라운드 6).
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
     * 시각을 화면이 읽을 사이트 표준시 문자열로 바꿉니다.
     *
     * 집계 경계와 **같은 시계**를 써야 합니다 — 경계는 이 클래스가, 표기는 다른 곳이 정하면
     * 「오늘 KPI」와 목록의 날짜가 서로 다른 하루를 가리킵니다(#682 S3 라운드 6, 설계 결정).
     * 일반 기능의 사이트 기준 변환은 `TimezoneHelper::toSiteDateTimeString()` 이 맡고,
     * 방문자 통계만 집계 경계와의 일치를 위해 이 시계를 씁니다.
     *
     * 오프셋을 붙이지 않습니다 — 붙이면 브라우저가 자기 시간대로 다시 옮겨 기준이 깨집니다.
     *
     * @param  Carbon|null  $moment  변환할 시각
     * @return string|null Y-m-d H:i:s 형식 (값이 없으면 null)
     */
    public static function toSiteDateTimeString(?Carbon $moment): ?string
    {
        if (! $moment) {
            return null;
        }

        return $moment->copy()->setTimezone(self::siteTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * 저장된 ISO8601 문자열을 화면이 읽을 사이트 표준시 문자열로 바꿉니다.
     *
     * 설정에 적히는 시각(클라우드 목록 갱신 등)은 저장값이 스스로 시간대를 말해야 하므로
     * 오프셋 있는 ISO8601 로 남습니다. 화면으로 나가는 경계에서만 이 변환을 태웁니다 —
     * 오프셋이 실린 채 나가면 `| datetime` 파이프가 브라우저 시간대로 다시 옮겨 사이트
     * 기준이 깨집니다(#682 S3 라운드 7, 설계 결정 「응답 경계에서 변환」).
     *
     * @param  mixed  $value  저장된 시각 (문자열이 아니면 null 로 본다)
     * @return string|null Y-m-d H:i:s 형식 (값이 없거나 해석 불가면 null)
     */
    public static function storedToSiteDateTimeString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return self::toSiteDateTimeString(Carbon::parse($value));
        } catch (Throwable) {
            return null;
        }
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
