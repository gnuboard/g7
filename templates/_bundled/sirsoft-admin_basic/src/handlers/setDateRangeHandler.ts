/**
 * 날짜 범위 프리셋 핸들러
 *
 * 날짜 필터의 빠른 선택 버튼(오늘, 일주일, 1개월, 3개월, 6개월, 1년)을 처리합니다.
 * 선택된 프리셋에 따라 시작일과 종료일을 자동으로 계산하여 반환합니다.
 * 시/분/초까지 포함한 datetime-local 형식(YYYY-MM-DDTHH:mm:ss)으로 반환합니다.
 * 상태 업데이트는 레이아웃 JSON에서 sequence + setState로 처리합니다.
 */

/**
 * 날짜 프리셋 타입
 */
type DatePreset = 'today' | 'week' | 'month' | '3months' | '6months' | '1year';

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
 * 날짜를 datetime-local 형식(YYYY-MM-DDTHH:mm:ss)으로 포맷합니다.
 *
 * @param date Date 객체
 * @returns YYYY-MM-DDTHH:mm:ss 형식의 문자열
 */
function formatDateTimeLocal(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`;
}

/**
 * 프리셋에 따른 날짜 범위를 계산합니다.
 *
 * @param preset 날짜 프리셋
 * @returns 시작일(00:00:00)과 종료일(23:59:59)
 */
function calculateDateRange(preset: DatePreset): { startDate: string; endDate: string } {
    const now = new Date();

    // 종료일: 오늘 23:59:59
    const end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
    const endDate = formatDateTimeLocal(end);

    // 시작일 계산
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);

    switch (preset) {
        case 'today':
            // 시작일 = 오늘 00:00:00 (이미 설정됨)
            break;

        case 'week':
            start.setDate(start.getDate() - 6); // 오늘 포함 7일
            break;

        case 'month':
            start.setMonth(start.getMonth() - 1);
            break;

        case '3months':
            start.setMonth(start.getMonth() - 3);
            break;

        case '6months':
            start.setMonth(start.getMonth() - 6);
            break;

        case '1year':
            start.setFullYear(start.getFullYear() - 1);
            break;
    }

    const startDate = formatDateTimeLocal(start);

    return { startDate, endDate };
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
    const { preset } = action.params || { preset: 'today' as DatePreset };

    const { startDate, endDate } = calculateDateRange(preset);

    return {
        startDate,
        endDate,
        preset,
    };
}
