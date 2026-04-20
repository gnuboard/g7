import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { ActionMenuItem } from './ActionMenu';
export interface DataGridCellChild {
    id?: string;
    type: 'basic' | 'composite';
    name: string;
    props?: Record<string, any>;
    text?: string;
    children?: DataGridCellChild[];
    condition?: string;
}
export interface DataGridColumn {
    field: string;
    header: string;
    width?: string;
    sortable?: boolean;
    hidden?: boolean;
    required?: boolean;
    render?: (value: any, row: any) => React.ReactNode;
    cellChildren?: DataGridCellChild[];
}
/**
 * 확장 행(Expandable Row)에 렌더링할 자식 컴포넌트 정의
 * 레이아웃 JSON에서 사용됩니다.
 */
export interface DataGridExpandChildren {
    id?: string;
    type: 'basic' | 'composite';
    name: string;
    props?: Record<string, any>;
    text?: string;
    children?: DataGridExpandChildren[];
    iteration?: {
        source: string;
        item_var?: string;
        index_var?: string;
    };
}
/**
 * 서브 행(Sub Row)에 렌더링할 자식 컴포넌트 정의 (v1.6.0+)
 *
 * 각 데이터 행 아래에 추가 정보를 표시하는 병합된 행입니다.
 * expandable row와 달리 항상 표시되거나 조건부로 표시됩니다.
 * 모든 컬럼이 병합된 단일 셀로 렌더링됩니다.
 *
 * @example
 * // 배송비 요약 표시
 * {
 *   "subRowChildren": [
 *     {
 *       "type": "basic",
 *       "name": "Span",
 *       "props": { "className": "text-sm text-gray-500" },
 *       "text": "{{row.fee_summary}}"
 *     }
 *   ],
 *   "subRowCondition": "{{row.charge_policy !== 'free'}}"
 * }
 */
export interface DataGridSubRowChildren {
    id?: string;
    type: 'basic' | 'composite';
    name: string;
    props?: Record<string, any>;
    text?: string;
    children?: DataGridSubRowChildren[];
    condition?: string;
}
/**
 * 푸터 행(Footer Row)의 각 셀 정의 (v1.19.0+)
 *
 * 테이블 하단에 합계/요약 행을 표시합니다.
 * 각 셀은 특정 컬럼의 field에 매핑되거나, colSpan으로 여러 컬럼을 병합합니다.
 */
export interface DataGridFooterCell {
    /** 매핑할 컬럼 field명 (이 필드의 컬럼 위치에 셀 배치) */
    field?: string;
    /** 병합할 컬럼 수 (field 미지정 시 사용) */
    colSpan?: number;
    /** 셀 CSS 클래스 */
    className?: string;
    /** 셀 내부에 렌더링할 컴포넌트 (cellChildren과 동일 구조) */
    children?: DataGridCellChild[];
    /** 단순 텍스트 (children 미사용 시) */
    text?: string;
}
export interface DataGridProps {
    /** DOM id (transition_overlay.target / overlay_target 으로 spinner mount 영역 지정용) */
    id?: string;
    columns: DataGridColumn[];
    data: any[];
    sortable?: boolean;
    pagination?: boolean;
    pageSize?: number;
    onRowClick?: (row: any) => void;
    className?: string;
    style?: React.CSSProperties;
    sortField?: string | null;
    sortDirection?: 'asc' | 'desc';
    onSortChange?: (field: string, direction: 'asc' | 'desc') => void;
    selectable?: boolean;
    selectedIds?: (string | number)[];
    onSelectionChange?: (ids: (string | number)[]) => void;
    idField?: string;
    showColumnSelector?: boolean;
    visibleColumns?: string[];
    onColumnVisibilityChange?: (fields: string[]) => void;
    responsiveBreakpoint?: number;
    cardRenderer?: (row: any, columns: DataGridColumn[]) => React.ReactNode;
    rowActions?: ActionMenuItem[];
    onRowAction?: (actionId: string | number, row: any) => void;
    alwaysShowPagination?: boolean;
    serverSidePagination?: boolean;
    serverCurrentPage?: number;
    serverTotalPages?: number;
    onPageChange?: (page: number) => void;
    prevText?: string;
    nextText?: string;
    columnSelectorText?: string;
    emptyMessage?: string;
    actionsColumnHeader?: string;
    requiredText?: string;
    selectedCountText?: string;
    loadErrorMessage?: string;
    showFirstLast?: boolean;
    expandable?: boolean;
    expandedRowIds?: (string | number)[];
    onExpandChange?: (ids: (string | number)[]) => void;
    expandChildren?: DataGridExpandChildren[];
    expandedRowRender?: (row: any) => React.ReactNode;
    expandColumnWidth?: string;
    expandIconExpanded?: IconName;
    expandIconCollapsed?: IconName;
    /** 행 단위 조건부 확장 필드명 — 해당 필드가 truthy인 행만 확장 가능 */
    expandConditionField?: string;
    /**
     * 동적 컬럼 배열 (v1.4.0+)
     *
     * 기존 columns 배열에 동적으로 병합될 컬럼들입니다.
     * insertAfterField가 지정된 경우 해당 필드 뒤에 삽입되고,
     * 그렇지 않으면 columns 배열 끝에 추가됩니다.
     *
     * 모듈 핸들러의 resultTo 패턴과 함께 사용하여
     * 환경설정 기반 동적 컬럼을 구현할 수 있습니다.
     *
     * @example
     * // 레이아웃 JSON에서 init_action으로 동적 컬럼 생성
     * {
     *   "handler": "sirsoft-ecommerce.buildProductColumns",
     *   "params": { ... },
     *   "resultTo": { "target": "_local", "key": "dynamicCols" }
     * }
     *
     * // DataGrid에서 동적 컬럼 사용
     * {
     *   "name": "DataGrid",
     *   "props": {
     *     "columns": [...],
     *     "dynamicColumns": "{{_local.dynamicCols}}"
     *   }
     * }
     */
    dynamicColumns?: DataGridColumn[];
    /**
     * 동적 컬럼 삽입 위치를 지정하는 필드명
     * 해당 필드 뒤에 dynamicColumns가 삽입됩니다.
     * 지정하지 않으면 columns 끝에 추가됩니다.
     */
    dynamicColumnsInsertAfter?: string;
    /**
     * expandChildren에 전달할 추가 컨텍스트 (v1.5.0+)
     *
     * expandChildren 렌더링 시 row 외에 추가로 전달할 데이터입니다.
     * 레이아웃 JSON에서 _local, _computed 값 등을 전달할 때 사용합니다.
     *
     * @example
     * {
     *   "name": "DataGrid",
     *   "props": {
     *     "expandContext": {
     *       "optionColumns": "{{_local.optionColumns}}",
     *       "currencies": "{{_computed.optionCurrencyColumns}}"
     *     }
     *   }
     * }
     */
    expandContext?: Record<string, any>;
    /**
     * 부모 컴포넌트 컨텍스트 (내부 사용)
     *
     * DynamicRenderer에서 전달되며, expandChildren 내부 액션에서
     * G7Core.state.setLocal()이 부모 상태를 업데이트할 수 있도록 합니다.
     */
    __componentContext?: {
        state?: any;
        setState?: (updates: any) => void;
    };
    /**
     * 서브 행에 렌더링할 컴포넌트 정의
     *
     * 각 데이터 행 아래에 추가 정보를 표시합니다.
     * 모든 컬럼이 병합된 단일 셀로 렌더링됩니다.
     * 이 prop이 없거나 빈 배열이면 서브 행이 표시되지 않습니다.
     *
     * @example
     * // 배송비 요약을 서브 행으로 표시
     * {
     *   "subRowChildren": [
     *     {
     *       "type": "basic",
     *       "name": "Span",
     *       "props": { "className": "text-sm text-gray-500" },
     *       "text": "배송비: {{row.fee_summary}}"
     *     }
     *   ]
     * }
     */
    subRowChildren?: DataGridSubRowChildren[];
    /**
     * 서브 행 표시 조건 (선택적)
     *
     * JavaScript 표현식으로, true일 때만 서브 행이 표시됩니다.
     * row 객체에 접근할 수 있습니다.
     *
     * @example
     * "{{row.charge_policy !== 'free'}}"  // 무료 배송이 아닐 때만 표시
     * "{{row.fee_summary}}"               // fee_summary가 있을 때만 표시
     */
    subRowCondition?: string;
    /**
     * 서브 행 셀의 CSS 클래스 (선택적)
     *
     * @default "px-6 py-2 bg-gray-50 dark:bg-gray-750"
     */
    subRowClassName?: string;
    /**
     * 서브 행 렌더 함수 (선택적)
     *
     * subRowChildren 대신 함수로 서브 행 내용을 렌더링합니다.
     * TypeScript/React 코드에서 직접 사용할 때 유용합니다.
     */
    subRowRender?: (row: any) => React.ReactNode;
    /**
     * 푸터 행 셀 정의 (v1.19.0+)
     *
     * 테이블 하단에 합계/요약 행을 렌더링합니다.
     * 각 셀은 field로 컬럼 위치에 매핑하거나 colSpan으로 병합합니다.
     *
     * @example
     * {
     *   "footerCells": [
     *     { "field": "product_info", "text": "합계", "className": "font-bold" },
     *     { "field": "quantity", "text": "3개" },
     *     { "field": "subtotal", "text": "690,000원", "className": "font-bold" }
     *   ]
     * }
     */
    footerCells?: DataGridFooterCell[];
    /**
     * 푸터 행 CSS 클래스 (선택적)
     *
     * @default "bg-gray-50 dark:bg-gray-800/50 font-medium"
     */
    footerClassName?: string;
    /**
     * 모바일 카드 뷰에서 푸터 렌더링용 컴포넌트 (선택적)
     *
     * 미지정 시 footerCells를 key-value 형태로 자동 렌더링합니다.
     */
    footerCardChildren?: DataGridCellChild[];
}
/**
 * DataGrid 집합 컴포넌트
 *
 * Table 기본 컴포넌트를 조합하여 데이터 그리드 UI를 구성합니다.
 * 정렬, 필터, 페이지네이션, 체크박스 선택, 컬럼 선택, 반응형 카드 뷰 기능을 포함합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "DataGrid",
 *   "props": {
 *     "columns": [
 *       {"field": "name", "header": "$t:admin.users.name", "sortable": true, "required": true},
 *       {"field": "email", "header": "$t:admin.users.email", "required": true}
 *     ],
 *     "data": "{{users.data}}",
 *     "sortable": true,
 *     "pagination": true,
 *     "pageSize": 10,
 *     "selectable": true,
 *     "showColumnSelector": true,
 *     "prevText": "$t:common.prev",
 *     "nextText": "$t:common.next",
 *     "columnSelectorText": "$t:common.column_selector",
 *     "emptyMessage": "$t:common.no_data",
 *     "actionsColumnHeader": "$t:common.actions",
 *     "requiredText": "$t:common.required"
 *   }
 * }
 */
export declare const DataGrid: React.FC<DataGridProps>;
