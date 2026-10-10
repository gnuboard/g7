/**
 * 날짜 범위 프리셋 핸들러
 *
 * 날짜 필터의 빠른 선택 버튼(오늘, 3일간, 일주일, 1개월, 3개월, 6개월, 1년, 전체)을 처리합니다.
 * 선택된 프리셋에 따라 시작일과 종료일을 자동으로 계산하여 반환합니다. 「오늘」은 계정 시간대(회원 → 사이트 기본)의 날짜다.
 * 상태 업데이트는 레이아웃 JSON에서 sequence + setState로 처리합니다.
 */

import { calendarDateIn, formatCalendarDate, resolveAccountTimezone } from '../utils/timezoneDate';

/**
 * 날짜 프리셋 타입
 */
type DatePreset = 'today' | '3days' | 'week' | 'month' | '3months' | '6months' | '1year' | 'all';

interface SetDateRangeParams {
    /** 날짜 프리셋 타입 */
    preset: DatePreset;
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
 * 그 달력 날짜를 UTC 자정 Date 로 들고 `setUTC*` 로 계산해 브라우저 시간대가 끼어들지 않게 한다.
 *
 * @param preset 날짜 프리셋
 * @returns 시작일과 종료일
 */
function calculateDateRange(preset: DatePreset): { startDate: string; endDate: string } {
    const today = calendarDateIn(new Date(), resolveAccountTimezone());
    const endDate = formatCalendarDate(today);
    const start = new Date(today);

    switch (preset) {
        case 'today':
            break;

        case '3days':
            start.setUTCDate(start.getUTCDate() - 2); // 오늘 포함 3일
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

        case 'all':
        default:
            return { startDate: '', endDate: '' };
    }

    return { startDate: formatCalendarDate(start), endDate };
}

/**
 * 날짜 범위 프리셋 핸들러
 *
 * 날짜 필터의 빠른 선택 버튼 클릭 시 호출됩니다.
 * 선택된 프리셋에 따라 시작일과 종료일을 계산하여 반환합니다.
 *
 * @example
 * ```json
 * {
 *   "type": "click",
 *   "handler": "sequence",
 *   "actions": [
 *     {
 *       "handler": "sirsoft-ecommerce.setDateRange",
 *       "params": { "preset": "today" }
 *     },
 *     {
 *       "handler": "setState",
 *       "params": {
 *         "target": "_local",
 *         "filter.dateQuick": "{{$prev.preset}}",
 *         "filter.startDate": "{{$prev.startDate}}",
 *         "filter.endDate": "{{$prev.endDate}}"
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
    const { preset } = action.params || { preset: 'today' };

    // 날짜 범위 계산
    const { startDate, endDate } = calculateDateRange(preset);

    // 계산된 결과 반환 (상태 업데이트는 레이아웃 JSON의 sequence + setState에서 처리)
    return {
        startDate,
        endDate,
        preset,
    };
}
