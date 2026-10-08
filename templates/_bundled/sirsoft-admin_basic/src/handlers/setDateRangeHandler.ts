/**
 * 날짜 범위 프리셋 핸들러
 *
 * 날짜 필터의 빠른 선택 버튼(오늘, 일주일, 1개월, 3개월, 6개월, 1년)을 처리합니다.
 * 선택된 프리셋에 따라 시작일과 종료일을 자동으로 계산하여 반환합니다. 「오늘」은 계정 시간대(회원 → 사이트 기본)의 날짜다.
 * 기본은 시/분/초까지 포함한 datetime-local 형식(YYYY-MM-DDTHH:mm:ss)이고, `format: 'date'` 를 주면
 * `<input type="date">` 가 받는 YYYY-MM-DD 로 돌려준다 — datetime 값은 날짜 칸이 받지 못해 빈칸으로 보인다.
 * 상태 업데이트는 레이아웃 JSON에서 sequence + setState로 처리합니다.
 */

import { calendarDateIn, formatCalendarDate, resolveAccountTimezone } from '../utils/timezoneDate';

/**
 * 날짜 프리셋 타입
 */
type DatePreset = 'today' | 'week' | 'month' | '3months' | '6months' | '1year';

/**
 * 반환 형식 — `datetime`(기본, YYYY-MM-DDTHH:mm:ss) 또는 `date`(YYYY-MM-DD)
 */
type DateRangeFormat = 'date' | 'datetime';

interface SetDateRangeParams {
    /** 날짜 프리셋 타입 */
    preset: DatePreset;
    /** 반환 형식 (기본 datetime — 기존 호출처 무영향) */
    format?: DateRangeFormat;
}

interface SetDateRangeResult {
    startDate: string;
    endDate: string;
    preset: DatePreset;
}

/**
 * 프리셋에 따른 날짜 범위를 계산합니다.
 *
 * 「오늘」은 계정 시간대(회원 시간대 → 사이트 기본 시간대)의 달력 날짜다. 서버가 목록의 날짜 필터를 그 시간대의
 * 하루로 해석하므로, 브라우저 시간대로 만들면 둘의 날짜가 다른 시간에 「오늘」이 어제·내일로 어긋난다.
 *
 * @param preset 날짜 프리셋
 * @param format 반환 형식
 * @param now 기준 순간 (기본 현재)
 * @returns 시작일(00:00:00)과 종료일(23:59:59)
 */
function calculateDateRange(
    preset: DatePreset,
    format: DateRangeFormat = 'datetime',
    now: Date = new Date(),
): { startDate: string; endDate: string } {
    const today = calendarDateIn(now, resolveAccountTimezone());
    const start = new Date(today);

    switch (preset) {
        case 'today':
            break;

        case 'week':
            start.setUTCDate(start.getUTCDate() - 6); // 오늘 포함 7일
            break;

        case 'month':
            start.setUTCMonth(start.getUTCMonth() - 1);
            break;

        case '3months':
            start.setUTCMonth(start.getUTCMonth() - 3);
            break;

        case '6months':
            start.setUTCMonth(start.getUTCMonth() - 6);
            break;

        case '1year':
            start.setUTCFullYear(start.getUTCFullYear() - 1);
            break;
    }

    const startDay = formatCalendarDate(start);
    const endDay = formatCalendarDate(today);

    if (format === 'date') {
        return { startDate: startDay, endDate: endDay };
    }

    return { startDate: `${startDay}T00:00:00`, endDate: `${endDay}T23:59:59` };
}

/**
 * 날짜 범위 프리셋 핸들러
 *
 * 날짜 필터의 빠른 선택 버튼 클릭 시 호출됩니다.
 * 선택된 프리셋에 따라 시작일(00:00:00)과 종료일(23:59:59)을 계산하여 반환합니다.
 *
 * @example
 * ```json
 * {
 *   "type": "click",
 *   "handler": "sequence",
 *   "actions": [
 *     {
 *       "handler": "sirsoft-admin_basic.setDateRange",
 *       "params": { "preset": "today" }
 *     },
 *     // 날짜 칸(<input type="date">)에 담을 때는 "params": { "preset": "today", "format": "date" }
 *     {
 *       "handler": "setState",
 *       "params": {
 *         "target": "local",
 *         "filter.dateQuick": "{{$prev.preset}}",
 *         "filter.dateFrom": "{{$prev.startDate}}",
 *         "filter.dateTo": "{{$prev.endDate}}"
 *       }
 *     }
 *   ]
 * }
 * ```
 *
 * @param action 액션 정의 (params 포함)
 * @returns 계산된 날짜 범위 정보
 */
export function setDateRangeHandler(
    action: { params?: SetDateRangeParams }
): SetDateRangeResult {
    const { preset, format = 'datetime' } = action.params || { preset: 'today' as DatePreset };

    const { startDate, endDate } = calculateDateRange(preset, format === 'date' ? 'date' : 'datetime');

    return {
        startDate,
        endDate,
        preset,
    };
}
