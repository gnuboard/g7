/**
 * 날짜 범위 프리셋 핸들러
 *
 * 날짜 필터의 빠른 선택 버튼(오늘, 3일간, 일주일, 1개월, 3개월, 6개월, 1년, 전체)을 처리합니다.
 * 선택된 프리셋에 따라 시작일과 종료일을 자동으로 계산하여 반환합니다.
 * 상태 업데이트는 레이아웃 JSON에서 sequence + setState로 처리합니다.
 */

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
 * 날짜를 YYYY-MM-DD 형식으로 포맷합니다.
 *
 * @param date Date 객체
 * @returns YYYY-MM-DD 형식의 문자열
 */
function formatDate(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * 프리셋에 따른 날짜 범위를 계산합니다.
 *
 * @param preset 날짜 프리셋
 * @returns 시작일과 종료일
 */
function calculateDateRange(preset: DatePreset): { startDate: string; endDate: string } {
    const today = new Date();
    const endDate = formatDate(today);

    let startDate: string;

    switch (preset) {
        case 'today':
            startDate = endDate;
            break;

        case '3days': {
            const start = new Date(today);
            start.setDate(start.getDate() - 2); // 오늘 포함 3일
            startDate = formatDate(start);
            break;
        }

        case 'week': {
            const start = new Date(today);
            start.setDate(start.getDate() - 6); // 오늘 포함 7일
            startDate = formatDate(start);
            break;
        }

        case 'month': {
            const start = new Date(today);
            start.setMonth(start.getMonth() - 1);
            startDate = formatDate(start);
            break;
        }

        case '3months': {
            const start = new Date(today);
            start.setMonth(start.getMonth() - 3);
            startDate = formatDate(start);
            break;
        }

        case '6months': {
            const start = new Date(today);
            start.setMonth(start.getMonth() - 6);
            startDate = formatDate(start);
            break;
        }

        case '1year': {
            const start = new Date(today);
            start.setFullYear(start.getFullYear() - 1);
            startDate = formatDate(start);
            break;
        }

        case 'all':
        default:
            startDate = '';
            return { startDate: '', endDate: '' };
    }

    return { startDate, endDate };
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
