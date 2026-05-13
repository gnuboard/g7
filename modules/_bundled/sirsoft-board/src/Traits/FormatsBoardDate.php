<?php

namespace Modules\Sirsoft\Board\Traits;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;

/**
 * 게시판 날짜 포맷 유틸리티 Trait
 *
 * PostResource, CommentResource에서 공유하는 날짜 포맷 로직.
 * 전역 설정(display.date_display_format)에 따라 표준형 또는 유동형으로 포맷합니다.
 */
trait FormatsBoardDate
{
    /**
     * 게시글/댓글 작성일을 표시용 문자열로 포맷합니다.
     *
     * @param  mixed   $dateTime  날짜/시간 (Carbon, string, null)
     * @param  string  $format    포맷 방식 ('standard' | 'relative')
     * @return string  포맷된 날짜 문자열
     */
    protected function formatCreatedAtFormat(mixed $dateTime, string $format = 'standard'): string
    {
        if (! $dateTime) {
            return '';
        }

        $carbon = $dateTime instanceof Carbon ? $dateTime : Carbon::parse($dateTime);
        $userCarbon = TimezoneHelper::toUserCarbon($carbon);
        $now = TimezoneHelper::toUserCarbon(Carbon::now());

        $diffInMinutes = (int) $now->diffInMinutes($userCarbon, absolute: true);
        $diffInHours = (int) $now->diffInHours($userCarbon, absolute: true);

        // 1시간 미만: N분 전 (공통)
        if ($diffInMinutes < 60) {
            if ($diffInMinutes < 1) {
                return '방금 전';
            }

            // 10분 이상은 10분 단위로 내림 (예: 21분 → 20분 전)
            if ($diffInMinutes >= 10) {
                $rounded = (int) floor($diffInMinutes / 10) * 10;

                return $rounded.'분 전';
            }

            return $diffInMinutes.'분 전';
        }

        // 1~23시간: N시간 전 (공통)
        if ($diffInHours < 24) {
            return $diffInHours.'시간 전';
        }

        if ($format === 'relative') {
            // 유동형: N일 전 → N개월 전 → N년 전
            $diffInDays = (int) $now->diffInDays($userCarbon, absolute: true);
            $diffInMonths = (int) $now->diffInMonths($userCarbon, absolute: true);
            $diffInYears = (int) $now->diffInYears($userCarbon, absolute: true);

            if ($diffInYears >= 1) {
                return $diffInYears.'년 전';
            }

            if ($diffInMonths >= 1) {
                return $diffInMonths.'개월 전';
            }

            return $diffInDays.'일 전';
        }

        // 표준형: MM-DD (올해) → YY-MM-DD (지난해 이전)
        if ($userCarbon->year === $now->year) {
            return $userCarbon->format('m-d');
        }

        return $userCarbon->format('y-m-d');
    }

    /**
     * 게시글/댓글 작성일을 요일 포함 전체 날짜+시간 문자열로 포맷합니다.
     *
     * 예시: "2026-03-18 화요일 14:30"
     *
     * @param  mixed  $dateTime  날짜/시간 (Carbon, string, null)
     * @return string  요일 포함 날짜 문자열
     */
    protected function formatCreatedAt(mixed $dateTime): string
    {
        if (! $dateTime) {
            return '';
        }

        $carbon = $dateTime instanceof Carbon ? $dateTime : Carbon::parse($dateTime);
        $userCarbon = TimezoneHelper::toUserCarbon($carbon);

        $weekdays = ['일', '월', '화', '수', '목', '금', '토'];
        $weekday = $weekdays[$userCarbon->dayOfWeek];

        return $userCarbon->format('Y-m-d').' '.$weekday.'요일 '.$userCarbon->format('H:i');
    }
}
