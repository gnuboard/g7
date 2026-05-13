/**
 * 상품 목록 DataGrid 동적 통화 컬럼 생성 핸들러
 *
 * 환경설정의 통화 목록을 기반으로 동적 통화 컬럼을 생성합니다.
 * DataGrid의 dynamicColumns prop으로 전달되어 기존 columns에 자동 병합됩니다.
 */

import type { ActionContext } from '../types';

interface Currency {
    code: string;
    name?: Record<string, string>;
    exchange_rate?: number | null;
    is_default?: boolean;
    rounding_unit?: string;
    rounding_method?: string;
}

interface ColumnDefinition {
    field: string;
    header: string;
    width?: string;
    sortable?: boolean;
    editable?: boolean;
    cellChildren?: any[];
    [key: string]: any;
}

interface BuildProductColumnsParams {
    currencies?: Currency[];
}

/**
 * 상품 목록 동적 통화 컬럼 생성 핸들러
 *
 * 환경설정의 통화 목록을 기반으로 외화 컬럼을 생성합니다.
 * 기본 통화(is_default: true)는 제외하고, exchange_rate가 설정된 외화만 포함합니다.
 *
 * DataGrid의 dynamicColumns prop과 함께 사용되며,
 * dynamicColumnsInsertAfter로 삽입 위치를 지정할 수 있습니다.
 *
 * @param action 액션 정의 (params 포함)
 * @param context 액션 컨텍스트
 * @returns 동적 통화 컬럼 배열
 */
export function buildProductColumnsHandler(
    action: { params?: BuildProductColumnsParams },
    context: ActionContext
): ColumnDefinition[] {
    const { currencies } = action.params || {};

    // 통화 설정이 없으면 빈 배열 반환
    if (!currencies || !Array.isArray(currencies) || currencies.length === 0) {
        return [];
    }

    // 현재 로케일 가져오기
    const locale = (context as any)._global?.locale || 'ko';

    // 기본 통화 제외한 외화 컬럼 생성
    const currencyColumns: ColumnDefinition[] = currencies
        .filter((c) => !c.is_default && c.exchange_rate)
        .map((c) => ({
            field: `multi_currency_selling_price.${c.code}`,
            header: c.name?.[locale] || c.code,
            width: '110px',
            sortable: false,
            editable: false,
            cellChildren: [
                {
                    type: 'basic',
                    name: 'Span',
                    props: {
                        className: 'text-sm text-gray-600 dark:text-gray-400',
                    },
                    text: `{{row.multi_currency_selling_price.${c.code}.formatted}}`,
                },
            ],
        }));

    return currencyColumns;
}
