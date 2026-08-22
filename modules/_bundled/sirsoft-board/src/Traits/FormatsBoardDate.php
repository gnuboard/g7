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
     * @param  mixed  $dateTime  날짜/시간 (Carbon, string, null)
     * @param  string  $format  포맷 방식 ('standard' | 'relative')
     * @return string 포맷된 날짜 문자열
     */
    protected function formatCreatedAtFormat(mixed $dateTime, string $format = 'standard'): string
    {
        if (! $dateTime) {
            return '';
        }

        $carbon = $dateTime instanceof Carbon ? $dateTime : Carbon::parse($dateTime);
        $userCarbon = TimezoneHelper::toUserCarbon($carbon);
        $now = TimezoneHelper::toUserCarbon(Carbon::now());

        // 미래 시각(시드/시계 어긋남 등)은 절댓값 차이를 양수처럼 표기하지 않고 just_now 로 폴백.
        if ($userCarbon->greaterThan($now)) {
            return __('sirsoft-board::messages.common.relative_time.just_now');
        }

        $diffInMinutes = (int) $now->diffInMinutes($userCarbon, absolute: true);
        $diffInHours = (int) $now->diffInHours($userCarbon, absolute: true);

        // 1시간 미만: N분 전 (공통). 라벨은 현재 로케일을 따른다.
        if ($diffInMinutes < 60) {
            if ($diffInMinutes < 1) {
                return __('sirsoft-board::messages.common.relative_time.just_now');
            }

            // 10분 이상은 10분 단위로 내림 (예: 21분 → 20분 전)
            $minutes = $diffInMinutes >= 10
                ? (int) floor($diffInMinutes / 10) * 10
                : $diffInMinutes;

            return __('sirsoft-board::messages.common.relative_time.minutes_ago', ['count' => $minutes]);
        }

        // 1~23시간: N시간 전 (공통)
        if ($diffInHours < 24) {
            return __('sirsoft-board::messages.common.relative_time.hours_ago', ['count' => $diffInHours]);
        }

        if ($format === 'relative') {
            // 유동형: N일 전 → N개월 전 → N년 전
            $diffInDays = (int) $now->diffInDays($userCarbon, absolute: true);
            $diffInMonths = (int) $now->diffInMonths($userCarbon, absolute: true);
            $diffInYears = (int) $now->diffInYears($userCarbon, absolute: true);

            if ($diffInYears >= 1) {
                return __('sirsoft-board::messages.common.relative_time.years_ago', ['count' => $diffInYears]);
            }

            if ($diffInMonths >= 1) {
                return __('sirsoft-board::messages.common.relative_time.months_ago', ['count' => $diffInMonths]);
            }

            return __('sirsoft-board::messages.common.relative_time.days_ago', ['count' => $diffInDays]);
        }

        // 표준형: MM-DD (올해) → YY-MM-DD (지난해 이전)
        if ($userCarbon->year === $now->year) {
            return $userCarbon->format('m-d');
        }

        return $userCarbon->format('y-m-d');
    }

    /**
     * 게시글/댓글 작성일을 전체 날짜+시간 문자열로 포맷합니다.
     *
     * 코어 BaseApiResource::formatDateTimeStringForUser() / 이커머스 주문일시와 동일한
     * "Y-m-d H:i:s" 형식 (사용자 타임존 보정 포함). 사용자 페이지 tooltip(title 속성) 과
     * 관리자 페이지 본문 표시 양쪽에서 사용된다.
     *
     * 예시: "2026-03-18 14:30:45"
     *
     * @param  mixed  $dateTime  날짜/시간 (Carbon, string, null)
     * @return string 전체 날짜+시간 문자열
     */
    protected function formatCreatedAt(mixed $dateTime): string
    {
        if (! $dateTime) {
            return '';
        }

        $carbon = $dateTime instanceof Carbon ? $dateTime : Carbon::parse($dateTime);

        return TimezoneHelper::toUserDateTimeString($carbon) ?? '';
    }
}
