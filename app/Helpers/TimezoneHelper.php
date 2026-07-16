<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\App;

/**
 * 타임존 변환 헬퍼
 *
 * Service, Listener 등 BaseApiResource 외부에서
 * 사용자 타임존으로 datetime을 변환할 때 사용합니다.
 */
class TimezoneHelper
{
    /**
     * 현재 요청의 사용자 타임존을 반환합니다.
     *
     * SetTimezone 미들웨어에서 설정한 값을 사용하며,
     * 미설정 시 config 기본값을 반환합니다.
     *
     * @return string 타임존 (예: 'Asia/Seoul')
     */
    public static function getUserTimezone(): string
    {
        if (App::bound('user_timezone')) {
            return App::make('user_timezone');
        }

        return config('app.default_user_timezone', 'Asia/Seoul');
    }

    /**
     * Carbon datetime을 사용자 타임존 ISO8601 문자열로 변환합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null ISO8601 문자열
     */
    public static function toUserTimezone(?Carbon $datetime): ?string
    {
        if (! $datetime) {
            return null;
        }

        return $datetime->copy()->setTimezone(static::getUserTimezone())->toIso8601String();
    }

    /**
     * Carbon datetime을 사용자 타임존 Y-m-d H:i:s 문자열로 변환합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d H:i:s 형식
     */
    public static function toUserDateTimeString(?Carbon $datetime): ?string
    {
        if (! $datetime) {
            return null;
        }

        return $datetime->copy()->setTimezone(static::getUserTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * Carbon datetime을 사용자 타임존 Y-m-d 문자열로 변환합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d 형식
     */
    public static function toUserDateString(?Carbon $datetime): ?string
    {
        if (! $datetime) {
            return null;
        }

        return $datetime->copy()->setTimezone(static::getUserTimezone())->format('Y-m-d');
    }

    /**
     * Carbon datetime을 사용자 타임존의 Carbon 인스턴스로 변환합니다.
     *
     * diffForHumans(), isToday() 등 후속 조작이 필요한 경우 사용합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return Carbon|null 사용자 타임존 Carbon
     */
    public static function toUserCarbon(?Carbon $datetime): ?Carbon
    {
        if (! $datetime) {
            return null;
        }

        return $datetime->copy()->setTimezone(static::getUserTimezone());
    }

    /**
     * 사이트 기본 타임존을 반환합니다.
     *
     * 쿠폰 유효기간 등 사이트 전체에 일관된 기준이 필요한 날짜 변환에 사용합니다.
     *
     * @return string 타임존 (예: 'Asia/Seoul')
     */
    public static function getSiteTimezone(): string
    {
        return config('app.default_user_timezone');
    }

    /**
     * Carbon datetime을 사이트 기본 타임존 Y-m-d 문자열로 변환합니다.
     *
     * 사용자 타임존이 아닌 사이트 기준 날짜를 반환합니다.
     * 쿠폰 유효기간 등 사이트 전체에 일관된 기준이 필요한 경우 사용합니다.
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d 형식
     */
    public static function toSiteDateString(?Carbon $datetime): ?string
    {
        if (! $datetime) {
            return null;
        }

        return $datetime->copy()->setTimezone(static::getSiteTimezone())->format('Y-m-d');
    }

    /**
     * 날짜 문자열을 사이트 기본 타임존 기준 하루 시작(00:00:00)으로 해석하여 UTC Carbon을 반환합니다.
     *
     * 예: '2026-03-15' + Asia/Seoul → 2026-03-15 00:00:00 KST → 2026-03-14 15:00:00 UTC
     *
     * @param  string|null  $dateString  Y-m-d 형식 날짜 문자열
     * @return Carbon|null UTC Carbon 인스턴스
     */
    public static function fromSiteDateStartOfDay(?string $dateString): ?Carbon
    {
        if (! $dateString) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $dateString, static::getSiteTimezone())
            ->startOfDay()
            ->utc();
    }

    /**
     * 날짜 문자열을 사이트 기본 타임존 기준 하루 끝(23:59:59)으로 해석하여 UTC Carbon을 반환합니다.
     *
     * 예: '2026-03-20' + Asia/Seoul → 2026-03-20 23:59:59 KST → 2026-03-20 14:59:59 UTC
     *
     * @param  string|null  $dateString  Y-m-d 형식 날짜 문자열
     * @return Carbon|null UTC Carbon 인스턴스
     */
    public static function fromSiteDateEndOfDay(?string $dateString): ?Carbon
    {
        if (! $dateString) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $dateString, static::getSiteTimezone())
            ->setTime(23, 59, 59)
            ->utc();
    }

    /**
     * Carbon datetime을 사이트 기본 타임존 datetime-local 입력 호환 문자열로 변환합니다.
     *
     * HTML <input type="datetime-local">은 'Y-m-d\TH:i' 형식을 요구합니다.
     *
     * 예: 2026-03-14 15:00:00 UTC → 2026-03-15T00:00 (Asia/Seoul)
     *
     * @param  Carbon|null  $datetime  변환할 datetime
     * @return string|null Y-m-d\TH:i 형식
     */
    public static function toSiteDateTimeLocalString(?Carbon $datetime): ?string
    {
        if (! $datetime) {
            return null;
        }

        return $datetime->copy()->setTimezone(static::getSiteTimezone())->format('Y-m-d\TH:i');
    }

    /**
     * datetime-local 입력값을 사이트 기본 타임존 기준으로 해석하여 UTC Carbon을 반환합니다.
     *
     * 예: '2026-03-15T09:30' + Asia/Seoul → 2026-03-15 09:30:00 KST → 2026-03-15 00:30:00 UTC
     *
     * @param  string|null  $dateTimeString  Y-m-d\TH:i 또는 Y-m-d H:i 형식
     * @return Carbon|null UTC Carbon 인스턴스
     */
    public static function fromSiteDateTime(?string $dateTimeString): ?Carbon
    {
        if (! $dateTimeString) {
            return null;
        }

        // datetime-local은 'Y-m-d\TH:i' 형식이지만, 'Y-m-d H:i:s' 등도 허용
        $normalized = str_replace('T', ' ', $dateTimeString);

        return Carbon::parse($normalized, static::getSiteTimezone())->utc();
    }
}
