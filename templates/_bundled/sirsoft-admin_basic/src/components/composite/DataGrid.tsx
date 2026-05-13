import React, { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { Table } from '../basic/Table';
import { Thead } from '../basic/Thead';
import { Tbody } from '../basic/Tbody';
import { Tfoot } from '../basic/Tfoot';
import { Tr } from '../basic/Tr';
import { Th } from '../basic/Th';
import { Td } from '../basic/Td';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Checkbox } from '../basic/Checkbox';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { ActionMenu, ActionMenuItem } from './ActionMenu';
import { Pagination } from './Pagination';
import { P } from '../basic/P';
import { A } from '../basic/A';
import { Label } from '../basic/Label';
import { Input } from '../basic/Input';
import { H1 } from '../basic/H1';
import { H2 } from '../basic/H2';
import { H3 } from '../basic/H3';
import { H4 } from '../basic/H4';
import { Img } from '../basic/Img';
import { Select } from '../basic/Select';
import { Textarea } from '../basic/Textarea';
import { MultilingualInput } from './MultilingualInput';
import { Toggle } from './Toggle';
import { StatusBadge } from './StatusBadge';
import { Badge } from './Badge';
import { HtmlContent } from './HtmlContent';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:DataGrid')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:DataGrid]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:DataGrid]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:DataGrid]', ...args),
};

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// cellChildren 및 expandChildren 렌더링을 위한 컴포넌트 맵
const componentMap: Record<string, React.ComponentType<any>> = {
  // basic 컴포넌트
  Div,
  Span,
  Button,
  Icon,
  Checkbox,
  P,
  A,
  Label,
  Input,
  H1,
  H2,
  H3,
  H4,
  Img,
  Select,
  Textarea,
  // Table 관련 컴포넌트 (expandChildren에서 SubGrid 렌더링용)
  Table,
  Thead,
  Tbody,
  Tfoot,
  Tr,
  Th,
  Td,
  // composite 컴포넌트
  MultilingualInput,
  Toggle,
  StatusBadge,
  Badge,
  HtmlContent,
  ActionMenu,
};

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

  // 정렬 기능 (외부 제어)
  sortField?: string | null;
  sortDirection?: 'asc' | 'desc';
  onSortChange?: (field: string, direction: 'asc' | 'desc') => void;

  // 체크박스 선택 기능
  selectable?: boolean;
  selectedIds?: (string | number)[];
  onSelectionChange?: (ids: (string | number)[]) => void;
  idField?: string;

  // 컬럼 표시/숨김 기능
  showColumnSelector?: boolean;
  visibleColumns?: string[];
  onColumnVisibilityChange?: (fields: string[]) => void;

  // 반응형 카드 뷰
  responsiveBreakpoint?: number;
  cardRenderer?: (row: any, columns: DataGridColumn[]) => React.ReactNode;

  // 행 액션 메뉴
  rowActions?: ActionMenuItem[];
  onRowAction?: (actionId: string | number, row: any) => void;

  // 페이지네이션 항상 표시 옵션
  alwaysShowPagination?: boolean;

  // 서버 사이드 페이지네이션 (API에서 페이지네이션 처리 시)
  serverSidePagination?: boolean;
  serverCurrentPage?: number;
  serverTotalPages?: number;
  onPageChange?: (page: number) => void;

  // 다국어 지원 텍스트 (레이아웃 JSON에서 $t: 문법으로 전달)
  prevText?: string;
  nextText?: string;
  columnSelectorText?: string;
  emptyMessage?: string;
  actionsColumnHeader?: string;
  requiredText?: string;
  selectedCountText?: string;
  loadErrorMessage?: string;

  // 페이지네이션 옵션
  showFirstLast?: boolean;

  // 확장 행 (Expandable Row) 기능 - v1.3.0+
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
  __componentContext?: { state?: any; setState?: (updates: any) => void };

  // 서브 행 (Sub Row) 기능 - v1.6.0+ (선택적)
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
 * 셀 값을 안전하게 렌더링 가능한 형태로 변환합니다.
 * React에서 객체를 직접 렌더링하면 에러가 발생하므로 이를 방지합니다.
 *
 * @param value 렌더링할 값
 * @returns 렌더링 가능한 문자열 또는 원본 값
 */
const safeRenderValue = (value: any): React.ReactNode => {
  // null, undefined는 빈 문자열로
  if (value === null || value === undefined) {
    return '';
  }

  // 원시 타입은 그대로 반환
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  // 배열인 경우 각 요소를 안전하게 변환 후 조인
  if (Array.isArray(value)) {
    return value.map((v) => safeRenderValue(v)).join(', ');
  }

  // 객체인 경우 (React 노드 제외)
  if (typeof value === 'object') {
    // React 엘리먼트인 경우 그대로 반환
    if (React.isValidElement(value)) {
      return value;
    }

    // MissingValue나 빈 객체 처리 (Laravel의 $this->when() 반환값)
    if (Object.keys(value).length === 0) {
      return '';
    }

    // 일반 객체는 JSON 문자열로 변환 시도
    try {
      return JSON.stringify(value);
    } catch {
      logger.warn('객체를 문자열로 변환할 수 없습니다:', value);
      return '[Object]';
    }
  }

  // 그 외의 경우 문자열로 변환
  return String(value);
};

/**
 * subRow 컨테이너의 표시 조건을 평가합니다.
 * JSX 인라인 조건에서 사용되므로 엔진 위임 불가 — 직접 평가합니다.
 *
 * @param condition 조건 문자열 (예: "{{row.charge_policy !== 'free'}}")
 * @param row 현재 행 데이터
 * @returns 조건 평가 결과
 */
const evaluateSubRowCondition = (condition: string | undefined, row: any): boolean => {
  if (!condition) return true;

  // {{...}} 패턴 추출
  const match = condition.match(/^\{\{(.+)\}\}$/);
  if (!match) return true;

  const expr = match[1].trim();

  try {
    // row 컨텍스트에서 표현식 평가
    // eslint-disable-next-line no-new-func
    const evaluator = new Function('row', `return ${expr}`);
    return !!evaluator(row);
  } catch (error) {
    logger.warn('subRow 조건 평가 실패:', condition, error);
    return true;
  }
};

/**
 * cellChildren 배열을 React 요소로 렌더링합니다.
 *
 * G7Core.renderItemChildren을 사용하여 템플릿 엔진의 모든 표현식 처리 기능을 활용합니다.
 * condition 속성은 엔진의 evaluateRenderCondition에서 네이티브로 지원됩니다 (engine-v1.17.1).
 */
const renderCellChildren = (
  cellChildren: DataGridCellChild[],
  row: any,
  value: any,
  keyPrefix: string = '',
  componentContext?: { state?: any; setState?: (updates: any) => void }
): React.ReactNode => {
  const G7Core = (window as any).G7Core;
  if (G7Core?.renderItemChildren) {
    const context = {
      row,
      value,
      $value: value,
    };

    return G7Core.renderItemChildren(
      cellChildren,
      context,
      componentMap,
      keyPrefix,
      componentContext ? { componentContext } : undefined
    );
  }

  logger.warn('G7Core.renderItemChildren을 사용할 수 없습니다.');
  return null;
};

/**
 * subRowChildren 배열을 React 요소로 렌더링합니다.
 *
 * @param subRowChildren 렌더링할 컴포넌트 정의 배열
 * @param row 현재 행 데이터
 * @param keyPrefix 키 접두사
 * @returns React 노드
 */
const renderSubRowChildren = (
  subRowChildren: DataGridSubRowChildren[],
  row: any,
  keyPrefix: string = '',
  componentContext?: { state?: any; setState?: (updates: any) => void }
): React.ReactNode => {
  const G7Core = (window as any).G7Core;

  if (G7Core?.renderItemChildren) {
    const context = {
      row,
      $value: row,
    };

    return G7Core.renderItemChildren(
      subRowChildren,
      context,
      componentMap,
      keyPrefix,
      componentContext ? { componentContext } : undefined
    );
  }

  logger.warn('G7Core.renderItemChildren을 사용할 수 없습니다.');
  return null;
};

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
export const DataGrid: React.FC<DataGridProps> = ({
  id,
  columns,
  data,
  sortable = true,
  pagination = false,
  pageSize = 10,
  onRowClick,
  className = '',
  style,
  // 정렬 (외부 제어)
  sortField: externalSortField,
  sortDirection: externalSortDirection,
  onSortChange,
  // 체크박스 선택
  selectable = false,
  selectedIds = [],
  onSelectionChange,
  idField = 'id',
  // 컬럼 표시/숨김
  showColumnSelector = false,
  visibleColumns: initialVisibleColumns,
  onColumnVisibilityChange,
  // 반응형
  responsiveBreakpoint = 768,
  cardRenderer,
  // 행 액션
  rowActions,
  onRowAction,
  // 페이지네이션 항상 표시
  alwaysShowPagination = false,
  // 서버 사이드 페이지네이션
  serverSidePagination = false,
  serverCurrentPage,
  serverTotalPages,
  onPageChange,
  // 다국어 텍스트
  prevText,
  nextText,
  columnSelectorText,
  emptyMessage,
  actionsColumnHeader,
  requiredText,
  selectedCountText,
  // 페이지네이션 옵션
  showFirstLast = true,
  // 확장 행 (Expandable Row)
  expandable = false,
  expandedRowIds: externalExpandedRowIds,
  onExpandChange,
  expandChildren,
  expandedRowRender,
  expandColumnWidth = '48px',
  expandIconExpanded = IconName.ChevronDown,
  expandIconCollapsed = IconName.ChevronRight,
  expandConditionField,
  // 동적 컬럼
  dynamicColumns,
  dynamicColumnsInsertAfter,
  // expandChildren 추가 컨텍스트
  expandContext,
  // 부모 컴포넌트 컨텍스트 (내부 사용)
  __componentContext,
  // 서브 행 (Sub Row) - 선택적
  subRowChildren,
  subRowCondition,
  subRowClassName = 'px-6 py-2 bg-gray-50 dark:bg-gray-750',
  subRowRender,
  // 푸터 행 (Footer Row) - v1.19.0+
  footerCells,
  footerClassName = 'bg-gray-50 dark:bg-gray-800/50 font-medium',
  footerCardChildren,
}) => {
  // props로 전달된 값이 없으면 undefined (Pagination이 기본 화살표 사용)
  const resolvedPrevText = prevText || undefined;
  const resolvedNextText = nextText || undefined;
  const resolvedColumnSelectorText = columnSelectorText ?? t('common.column_selector');
  const resolvedEmptyMessage = emptyMessage ?? t('common.no_data');
  const resolvedActionsColumnHeader = actionsColumnHeader ?? t('common.actions');
  const resolvedRequiredText = requiredText ?? t('common.required');
  const resolvedSelectedCountText = selectedCountText ?? t('common.selected_count');

  // G7Core.useControllableState 훅 참조 (Phase 2-2)
  const G7Core = (window as any).G7Core;
  const useControllableState = G7Core?.useControllableState as
    | (<T>(
        controlledValue: T | undefined,
        defaultValue: T,
        onChange?: (value: T) => void,
        options?: { isEqual?: (a: T, b: T) => boolean }
      ) => [T, (value: T | ((prev: T) => T)) => void])
    | undefined;
  const shallowArrayEqual = G7Core?.shallowArrayEqual as
    | (<T>(a: T[], b: T[]) => boolean)
    | undefined;

  // 정렬 상태 - useControllableState 적용
  // 외부 제어(externalSortField)가 있으면 Controlled, 없으면 Uncontrolled
  const [sortField, setSortField] = useControllableState
    ? useControllableState<string | null>(externalSortField, null, (value: string | null) => {
        if (value !== null) {
          onSortChange?.(value, sortDirection);
        }
      })
    : [externalSortField ?? null, () => {}];

  const [sortDirection, setSortDirection] = useControllableState
    ? useControllableState<'asc' | 'desc'>(externalSortDirection, 'asc', (value: 'asc' | 'desc') => {
        if (sortField !== null) {
          onSortChange?.(sortField, value);
        }
      })
    : [externalSortDirection ?? 'asc', () => {}];

  const [currentPage, setCurrentPage] = useState(1);

  // 확장 행 상태 관리 - useControllableState 적용 (Phase 2-2)
  // 외부 제어(externalExpandedRowIds)가 있으면 Controlled, 없으면 Uncontrolled
  const [expandedRowIds, setExpandedRowIds] = useControllableState
    ? useControllableState<(string | number)[]>(
        externalExpandedRowIds,
        [],
        onExpandChange,
        { isEqual: shallowArrayEqual }
      )
    : [externalExpandedRowIds ?? [], () => {}];
  const [isColumnSelectorOpen, setIsColumnSelectorOpen] = useState(false);
  const columnSelectorRef = useRef<HTMLDivElement>(null);

  // G7Core.useResponsive를 통해 반응형 상태 구독
  // G7Core는 위에서 이미 선언됨 (Phase 2-2)
  const useResponsive = G7Core?.useResponsive;
  const responsiveValue = useResponsive?.();
  const isMobileView = responsiveValue
    ? responsiveValue.width < responsiveBreakpoint
    : typeof window !== 'undefined' && window.innerWidth < responsiveBreakpoint;

  // 동적 컬럼 병합
  // dynamicColumns가 있으면 columns에 병합하고, dynamicColumnsInsertAfter 위치에 삽입
  const mergedColumns = useMemo(() => {
    if (!dynamicColumns || !Array.isArray(dynamicColumns) || dynamicColumns.length === 0) {
      return columns;
    }

    // 삽입 위치 찾기
    if (dynamicColumnsInsertAfter) {
      const insertIndex = columns.findIndex((col) => col.field === dynamicColumnsInsertAfter);
      if (insertIndex !== -1) {
        return [
          ...columns.slice(0, insertIndex + 1),
          ...dynamicColumns,
          ...columns.slice(insertIndex + 1),
        ];
      }
    }

    // 삽입 위치가 없으면 끝에 추가
    return [...columns, ...dynamicColumns];
  }, [columns, dynamicColumns, dynamicColumnsInsertAfter]);

  // 표시할 컬럼 관리 (hidden 속성 기반 초기값 설정)
  // 참고: mergedColumns가 변경되면 useEffect에서 동기화됨
  const [visibleColumns, setVisibleColumns] = useState<string[]>(() => {
    if (initialVisibleColumns && initialVisibleColumns.length > 0) return initialVisibleColumns;
    return columns.filter((col) => !col.hidden).map((col) => col.field);
  });

  // 외부 visibleColumns가 변경되면 내부 상태 동기화
  useEffect(() => {
    if (initialVisibleColumns && initialVisibleColumns.length > 0) {
      setVisibleColumns(initialVisibleColumns);
    }
  }, [initialVisibleColumns]);

  // columns prop이 변경되면 (다른 DataGrid = 다른 페이지) 내부 visibleColumns 리셋
  const prevColumnFieldsRef = useRef<string>('');
  useEffect(() => {
    const currentFields = columns.map(col => col.field).join(',');
    if (prevColumnFieldsRef.current && currentFields !== prevColumnFieldsRef.current) {
      // columns가 변경되었고, 외부 visibleColumns가 없으면 새 columns 기준으로 리셋
      if (!initialVisibleColumns || initialVisibleColumns.length === 0) {
        setVisibleColumns(columns.filter(col => !col.hidden).map(col => col.field));
      }
    }
    prevColumnFieldsRef.current = currentFields;
  }, [columns, initialVisibleColumns]);

  // 동적 컬럼이 추가되면 visibleColumns에 자동으로 포함
  // 단, 외부에서 전달된 initialVisibleColumns가 있으면 그 값을 존중
  const prevDynamicFieldsRef = useRef<string[]>([]);
  const hasInitialColumnsRef = useRef<boolean | null>(null);

  useEffect(() => {
    if (dynamicColumns && dynamicColumns.length > 0) {
      const currentDynamicFields = dynamicColumns.map((col) => col.field);

      // 최초 실행 시 initialVisibleColumns 존재 여부 확인
      if (hasInitialColumnsRef.current === null) {
        hasInitialColumnsRef.current = initialVisibleColumns !== undefined && initialVisibleColumns.length > 0;

        // initialVisibleColumns가 있으면 현재 동적 필드를 "이미 알려진" 것으로 기록
        // 이렇게 하면 외부에서 관리하는 visibleColumns를 존중함
        if (hasInitialColumnsRef.current) {
          prevDynamicFieldsRef.current = currentDynamicFields;
          return; // 외부 visibleColumns 존중, 자동 추가 안 함
        }
      }

      const prevDynamicFields = prevDynamicFieldsRef.current;

      // 새로 추가된 동적 필드만 찾기 (기존에 없던 필드)
      const trulyNewFields = currentDynamicFields.filter(
        (field) => !prevDynamicFields.includes(field)
      );

      // 새로운 동적 필드가 있을 때만 자동 추가
      if (trulyNewFields.length > 0) {
        const fieldsToAdd = trulyNewFields.filter((field) => !visibleColumns.includes(field));
        if (fieldsToAdd.length > 0) {
          const updatedVisible = [...visibleColumns, ...fieldsToAdd];
          setVisibleColumns(updatedVisible);
          onColumnVisibilityChange?.(updatedVisible);
        }
      }

      // 현재 동적 필드 목록 저장
      prevDynamicFieldsRef.current = currentDynamicFields;
    }
  }, [dynamicColumns]); // visibleColumns 의존성 제외하여 무한 루프 방지

  // 데이터가 변경되면 첫 페이지로 리셋 (서버 사이드 페이지네이션이 아닐 때만)
  useEffect(() => {
    if (!serverSidePagination) {
      setCurrentPage(1);
    }
  }, [data, serverSidePagination]);

  // 컬럼 선택 메뉴 외부 클릭 감지
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (columnSelectorRef.current && !columnSelectorRef.current.contains(event.target as Node)) {
        setIsColumnSelectorOpen(false);
      }
    };

    if (isColumnSelectorOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isColumnSelectorOpen]);

  // 표시할 컬럼 필터링 (mergedColumns에서 visibleColumns만 표시)
  // showColumnSelector가 false이고 외부 visibleColumns도 없으면 모든 비-hidden 컬럼 표시
  // (SPA 네비게이션 시 React 컴포넌트 재사용으로 이전 페이지의 visibleColumns가 남는 문제 방지)
  const displayColumns = useMemo(() => {
    if (!showColumnSelector && (!initialVisibleColumns || initialVisibleColumns.length === 0)) {
      return mergedColumns.filter((col) => !col.hidden);
    }
    return mergedColumns.filter((col) => visibleColumns.includes(col.field));
  }, [mergedColumns, visibleColumns, showColumnSelector, initialVisibleColumns]);

  // 정렬 처리
  const sortedData = useMemo(() => {
    if (!sortField || !sortable || !data) return data || [];

    return [...data].sort((a, b) => {
      const aValue = a[sortField];
      const bValue = b[sortField];

      if (aValue === bValue) return 0;

      const comparison = aValue > bValue ? 1 : -1;
      return sortDirection === 'asc' ? comparison : -comparison;
    });
  }, [data, sortField, sortDirection, sortable]);

  // 페이지네이션 처리
  const paginatedData = useMemo(() => {
    // 서버 사이드 페이지네이션인 경우 데이터를 그대로 사용 (이미 서버에서 페이징됨)
    if (serverSidePagination) return sortedData;
    if (!pagination) return sortedData;

    const start = (currentPage - 1) * pageSize;
    const end = start + pageSize;
    return sortedData.slice(start, end);
  }, [sortedData, currentPage, pageSize, pagination, serverSidePagination]);

  // 서버 사이드 페이지네이션인 경우 서버에서 전달된 값 사용
  const effectiveCurrentPage = serverSidePagination ? (serverCurrentPage || 1) : currentPage;
  const effectiveTotalPages = serverSidePagination ? (serverTotalPages || 1) : Math.ceil((sortedData?.length || 0) / pageSize);

  // 페이지 변경 핸들러
  const handlePageChange = useCallback((page: number) => {
    if (serverSidePagination && onPageChange) {
      onPageChange(page);
    } else {
      setCurrentPage(page);
    }
  }, [serverSidePagination, onPageChange]);

  // 정렬 토글 (Phase 2-2: useControllableState로 단순화)
  const handleSort = (field: string) => {
    if (!sortable) return;

    const column = columns.find((col) => col.field === field);
    if (column?.sortable === false) return;

    const newDirection: 'asc' | 'desc' = sortField === field && sortDirection === 'asc' ? 'desc' : 'asc';

    // useControllableState 사용 시: setter가 onChange 콜백 자동 호출
    // 폴백 시: 직접 onSortChange 호출
    if (useControllableState) {
      setSortField(field);
      setSortDirection(newDirection);
    } else if (onSortChange) {
      onSortChange(field, newDirection);
    }
  };

  // 전체 선택 처리
  const handleSelectAll = useCallback(() => {
    if (!onSelectionChange || !paginatedData) return;

    const allIds = paginatedData.map((row) => row[idField]);
    const allSelected = allIds.every((id) => selectedIds.includes(id));

    if (allSelected) {
      onSelectionChange(selectedIds.filter((id) => !allIds.includes(id)));
    } else {
      const newSelectedIds = [...new Set([...selectedIds, ...allIds])];
      onSelectionChange(newSelectedIds);
    }
  }, [paginatedData, selectedIds, onSelectionChange, idField]);

  // 개별 선택 처리
  const handleSelectRow = useCallback(
    (rowId: string | number) => {
      if (!onSelectionChange) return;

      if (selectedIds.includes(rowId)) {
        onSelectionChange(selectedIds.filter((id) => id !== rowId));
      } else {
        onSelectionChange([...selectedIds, rowId]);
      }
    },
    [selectedIds, onSelectionChange]
  );

  // 컬럼 표시/숨김 토글
  const handleColumnToggle = useCallback(
    (field: string) => {
      const column = mergedColumns.find((col) => col.field === field);
      if (column?.required) return;

      const newVisibleColumns = visibleColumns.includes(field)
        ? visibleColumns.filter((f) => f !== field)
        : [...visibleColumns, field];

      setVisibleColumns(newVisibleColumns);
      onColumnVisibilityChange?.(newVisibleColumns);
    },
    [mergedColumns, visibleColumns, onColumnVisibilityChange]
  );

  // 행 액션 처리
  const handleRowAction = useCallback(
    (actionId: string | number, row: any) => {
      onRowAction?.(actionId, row);
    },
    [onRowAction]
  );

  /**
   * 행 데이터 기반으로 rowActions의 disabled 상태를 결정합니다.
   * disabledField가 지정된 경우 해당 경로의 값이 falsy면 disabled 처리합니다.
   */
  const resolveRowActions = useCallback(
    (row: any): ActionMenuItem[] => {
      if (!rowActions) return [];
      return rowActions.map((action) => {
        let isDisabled = action.disabled ?? false;
        if (action.disabledField && !isDisabled) {
          const parts = action.disabledField.split('.');
          let value: any = row;
          for (const part of parts) {
            value = value?.[part];
          }
          if (!value) {
            isDisabled = true;
          }
        }
        return {
          ...action,
          disabled: isDisabled,
          onClick: () => handleRowAction(action.id, row),
        };
      });
    },
    [rowActions, handleRowAction]
  );

  // 행 단위 확장 가능 여부 확인
  const isRowExpandable = useCallback(
    (row: any): boolean => {
      if (!expandConditionField) return true;
      const value = row[expandConditionField];
      return Array.isArray(value) ? value.length > 0 : !!value;
    },
    [expandConditionField]
  );

  // 확장 행 토글 처리
  // 확장 토글 (Phase 2-2: useControllableState로 단순화)
  const handleExpandToggle = useCallback(
    (rowId: string | number) => {
      const newExpandedIds = expandedRowIds.includes(rowId)
        ? expandedRowIds.filter((id: string | number) => id !== rowId)
        : [...expandedRowIds, rowId];

      // useControllableState 사용 시: setter가 onChange 콜백 자동 호출
      // 폴백 시: 직접 onExpandChange 호출
      if (useControllableState) {
        setExpandedRowIds(newExpandedIds);
      } else if (onExpandChange) {
        onExpandChange(newExpandedIds);
      }
    },
    [expandedRowIds, onExpandChange, useControllableState, setExpandedRowIds]
  );

  // 행이 확장되었는지 확인
  const isRowExpanded = useCallback(
    (rowId: string | number) => expandedRowIds.includes(rowId),
    [expandedRowIds]
  );

  // 확장 행 콘텐츠 렌더링 (Phase 2-1: G7Core.renderExpandContent API 사용으로 간소화)
  const renderExpandedContent = useCallback(
    (row: any) => {
      // expandedRowRender 함수가 제공된 경우 우선 사용
      if (expandedRowRender) {
        return expandedRowRender(row);
      }

      // expandChildren이 제공된 경우 G7Core.renderExpandContent 사용
      // 기존 ~80줄의 복잡한 로직이 renderExpandContent API 내부로 캡슐화됨
      // G7Core는 컴포넌트 시작 부분에서 이미 선언됨 (Phase 2-2)
      if (expandChildren && expandChildren.length > 0 && G7Core?.renderExpandContent) {
        return G7Core.renderExpandContent({
          children: expandChildren,
          row,
          expandContext,
          componentContext: __componentContext,
          componentMap,
          keyPrefix: `expand-${row[idField]}`,
        });
      }

      return null;
    },
    // __componentContext?.state를 의존성에 추가하여 상태 변경 시 확장 영역이 다시 렌더링되도록 함
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [expandedRowRender, expandChildren, idField, expandContext, __componentContext, __componentContext?.state]
  );

  // 전체 선택 상태 계산
  const isAllSelected = useMemo(() => {
    if (!paginatedData || paginatedData.length === 0) return false;
    return paginatedData.every((row) => selectedIds.includes(row[idField]));
  }, [paginatedData, selectedIds, idField]);

  const isIndeterminate = useMemo(() => {
    if (!paginatedData || paginatedData.length === 0) return false;
    const selectedCount = paginatedData.filter((row) => selectedIds.includes(row[idField])).length;
    return selectedCount > 0 && selectedCount < paginatedData.length;
  }, [paginatedData, selectedIds, idField]);

  // 기본 카드 렌더러
  const defaultCardRenderer = (row: any, cols: DataGridColumn[]) => {
    const rowId = row[idField];
    const expanded = isRowExpanded(rowId);

    return (
      <Div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4">
        <Div className="flex items-start justify-between">
          {/* 확장 버튼 (카드 뷰) */}
          {expandable && isRowExpandable(row) && (
            <Button
              onClick={() => handleExpandToggle(rowId)}
              className="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors mr-2"
              aria-label={expanded ? 'Collapse card' : 'Expand card'}
            >
              <Icon
                name={expanded ? expandIconExpanded : expandIconCollapsed}
                className="w-4 h-4 text-gray-500 dark:text-gray-400"
              />
            </Button>
          )}

          {selectable && (
            <Checkbox
              checked={selectedIds.includes(row[idField])}
              onChange={() => handleSelectRow(row[idField])}
              className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded mr-3 mt-1"
            />
          )}
          <Div className="flex-1">
            <Div className="grid grid-cols-2 gap-x-4 gap-y-2">
              {cols.map((col) => (
                <Div key={col.field} className="min-w-0">
                  <Span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                    {col.header}
                  </Span>
                  <Div className="text-sm text-gray-900 dark:text-gray-200 mt-0.5 break-words">
                    {col.cellChildren && col.cellChildren.length > 0
                      ? renderCellChildren(col.cellChildren, row, row[col.field], `card-${row[idField]}-${col.field}`, __componentContext)
                      : col.render
                        ? col.render(row[col.field], row)
                        : safeRenderValue(row[col.field])}
                  </Div>
                </Div>
              ))}
            </Div>
          </Div>
          {rowActions && rowActions.length > 0 && (
            <Div className="ml-2">
              <ActionMenu
                items={resolveRowActions(row)}
                triggerLabel=""
                triggerIconName={IconName.EllipsisHorizontal}
              />
            </Div>
          )}
        </Div>

        {/* 확장된 콘텐츠 (카드 뷰) */}
        {expandable && expanded && isRowExpandable(row) && (
          <Div className="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
            {renderExpandedContent(row)}
          </Div>
        )}

        {/* 서브 행 콘텐츠 (카드 뷰) */}
        {(subRowChildren && subRowChildren.length > 0 || subRowRender) &&
          evaluateSubRowCondition(subRowCondition, row) && (
          <Div className={subRowClassName ? `mt-3 ${subRowClassName}` : 'mt-3 pt-3 border-t border-gray-100 dark:border-gray-700'}>
            {subRowRender
              ? subRowRender(row)
              : renderSubRowChildren(subRowChildren!, row, `card-subrow-${rowId}`, __componentContext)}
          </Div>
        )}
      </Div>
    );
  };

  // 모바일 카드 뷰
  if (isMobileView) {
    return (
      <Div id={id} className={`w-full ${className}`} style={style}>
        {/* 컬럼 선택 메뉴 */}
        {showColumnSelector && (
          <Div className="mb-4 flex justify-end">
            <Div ref={columnSelectorRef} className="relative">
              <Button
                onClick={() => setIsColumnSelectorOpen(!isColumnSelectorOpen)}
                className="flex items-center gap-2 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
              >
                <Icon name={IconName.Cog} className="w-4 h-4 text-gray-500 dark:text-gray-400" />
                <Span className="text-sm text-gray-700 dark:text-gray-300">
                  {resolvedColumnSelectorText}
                </Span>
              </Button>

              {isColumnSelectorOpen && (
                <Div className="absolute right-0 z-50 mt-2 w-64 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg">
                  <Div className="p-2 max-h-64 overflow-y-auto">
                    {mergedColumns.map((col) => (
                      <Div
                        key={col.field}
                        className={`flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-gray-700 ${
                          col.required ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
                        }`}
                        onClick={() => !col.required && handleColumnToggle(col.field)}
                      >
                        <Checkbox
                          checked={visibleColumns.includes(col.field)}
                          disabled={col.required}
                          onChange={() => {}}
                          className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded"
                        />
                        <Span className="text-sm text-gray-700 dark:text-gray-300">
                          {col.header}
                          {col.required && (
                            <Span className="text-xs text-gray-500 dark:text-gray-400 ml-1">
                              ({resolvedRequiredText})
                            </Span>
                          )}
                        </Span>
                      </Div>
                    ))}
                  </Div>
                </Div>
              )}
            </Div>
          </Div>
        )}

        {/* 선택된 항목 수 표시 */}
        {selectable && selectedIds.length > 0 && resolvedSelectedCountText && (
          <Div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {resolvedSelectedCountText.replace('{count}', String(selectedIds.length))}
          </Div>
        )}

        {/* 카드 목록 — body wrapper 에 `${id}__body` 부여 (pagination 제외 영역) */}
        <Div id={id ? `${id}__body` : undefined}>
          {paginatedData && paginatedData.length > 0 ? (
            paginatedData.map((row, index) => (
              <Div key={row[idField] || index}>
                {cardRenderer
                  ? cardRenderer(row, displayColumns)
                  : defaultCardRenderer(row, displayColumns)}
              </Div>
            ))
          ) : (
            <Div className="text-center py-8 text-gray-500 dark:text-gray-400">
              {resolvedEmptyMessage}
            </Div>
          )}
        </Div>

        {/* 푸터 (모바일 카드 뷰) - v1.19.0+ */}
        {footerCells && footerCells.length > 0 && paginatedData && paginatedData.length > 0 && (
          <Div className={`rounded-lg p-4 mt-2 ${footerClassName}`}>
            {footerCardChildren && footerCardChildren.length > 0
              ? renderCellChildren(footerCardChildren, {}, null, 'footer-card', __componentContext)
              : (
                <Div className="space-y-1">
                  {footerCells.filter(c => c.text || (c.children && c.children.length > 0)).map((cell, idx) => {
                    const column = displayColumns.find(col => col.field === cell.field);
                    return (
                      <Div key={`footer-mobile-${idx}`} className="flex justify-between text-sm">
                        <Span className="text-gray-500 dark:text-gray-400">{column?.header || ''}</Span>
                        <Span className={cell.className || 'text-gray-900 dark:text-gray-100'}>
                          {cell.children && cell.children.length > 0
                            ? renderCellChildren(cell.children, {}, null, `footer-mobile-${idx}`, __componentContext)
                            : cell.text || ''}
                        </Span>
                      </Div>
                    );
                  })}
                </Div>
              )
            }
          </Div>
        )}

        {/* 페이지네이션 */}
        {pagination && (alwaysShowPagination || effectiveTotalPages > 1) && (
          <Div className="flex justify-center mt-4">
            <Pagination
              currentPage={effectiveCurrentPage}
              totalPages={effectiveTotalPages}
              onPageChange={handlePageChange}
              showFirstLast={showFirstLast}
              maxVisiblePages={5}
              prevText={resolvedPrevText}
              nextText={resolvedNextText}
            />
          </Div>
        )}
      </Div>
    );
  }

  // 데스크톱 테이블 뷰
  return (
    <Div id={id} className={`w-full ${className}`} style={style}>
      {/* 컬럼 선택 메뉴 */}
      {showColumnSelector && (
        <Div className="mb-4 flex justify-end">
          <Div ref={columnSelectorRef} className="relative">
            <Button
              onClick={() => setIsColumnSelectorOpen(!isColumnSelectorOpen)}
              className="flex items-center gap-2 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
            >
              <Icon name={IconName.Cog} className="w-4 h-4 text-gray-500 dark:text-gray-400" />
              <Span className="text-sm text-gray-700 dark:text-gray-300">
                {resolvedColumnSelectorText}
              </Span>
            </Button>

            {isColumnSelectorOpen && (
              <Div className="absolute right-0 z-50 mt-2 w-64 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg">
                <Div className="p-2 max-h-64 overflow-y-auto">
                  {mergedColumns.map((col) => (
                    <Div
                      key={col.field}
                      className={`flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-gray-700 ${
                        col.required ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
                      }`}
                      onClick={() => !col.required && handleColumnToggle(col.field)}
                    >
                      <Checkbox
                        checked={visibleColumns.includes(col.field)}
                        disabled={col.required}
                        onChange={() => {}}
                        className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded"
                      />
                      <Span className="text-sm text-gray-700 dark:text-gray-300">
                        {col.header}
                        {col.required && (
                          <Span className="text-xs text-gray-500 dark:text-gray-400 ml-1">
                            ({resolvedRequiredText})
                          </Span>
                        )}
                      </Span>
                    </Div>
                  ))}
                </Div>
              </Div>
            )}
          </Div>
        </Div>
      )}

      {/* 선택된 항목 수 표시 */}
      {selectable && selectedIds.length > 0 && resolvedSelectedCountText && (
        <Div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
          {resolvedSelectedCountText.replace('{count}', String(selectedIds.length))}
        </Div>
      )}

      {/* 테이블 — body wrapper 에 `${id}__body` 부여 (pagination 제외 영역) */}
      <Div id={id ? `${id}__body` : undefined} className="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
        <Table className="w-full">
          <Thead className="bg-gray-50 dark:bg-gray-700">
            <Tr>
              {/* 확장 버튼 컬럼 */}
              {expandable && (
                <Th className="px-2 py-3" style={{ width: expandColumnWidth }}>
                  {/* 빈 헤더 - 확장 버튼용 */}
                </Th>
              )}

              {/* 체크박스 컬럼 */}
              {selectable && (
                <Th className="px-4 py-3 w-10">
                  <Checkbox
                    checked={isAllSelected}
                    ref={(el: HTMLInputElement | null) => {
                      if (el) {
                        el.indeterminate = isIndeterminate;
                      }
                    }}
                    onChange={handleSelectAll}
                    className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded"
                  />
                </Th>
              )}

              {/* 데이터 컬럼 */}
              {displayColumns.map((column) => (
                <Th
                  key={column.field}
                  className={`px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider ${
                    sortable && column.sortable !== false
                      ? 'cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600'
                      : ''
                  }`}
                  style={{ width: column.width, minWidth: column.width }}
                  onClick={() => handleSort(column.field)}
                >
                  {column.header}
                  {sortField === column.field && (
                    <Span className="ml-1">
                      {sortDirection === 'asc' ? '↑' : '↓'}
                    </Span>
                  )}
                </Th>
              ))}

              {/* 액션 컬럼 */}
              {rowActions && rowActions.length > 0 && (
                <Th className="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider w-24">
                  {resolvedActionsColumnHeader}
                </Th>
              )}
            </Tr>
          </Thead>
          <Tbody className={`bg-white dark:bg-gray-800 ${subRowClassName ? '' : 'divide-y divide-gray-200 dark:divide-gray-700'}`}>
            {paginatedData && paginatedData.length > 0 ? (
              paginatedData.map((row, rowIndex) => {
                const rowId = row[idField] || rowIndex;
                const expanded = isRowExpanded(rowId);

                return (
                  <React.Fragment key={rowId}>
                    {/* 데이터 행 */}
                    <Tr
                      className={`${
                        onRowClick ? 'cursor-pointer' : ''
                      } hover:bg-gray-50 dark:hover:bg-gray-700 ${
                        selectedIds.includes(row[idField]) ? 'bg-blue-50 dark:bg-blue-900/20' : ''
                      } ${subRowClassName && rowIndex > 0 ? 'border-t border-gray-200 dark:border-gray-700' : ''}`}
                      onClick={() => onRowClick?.(row)}
                    >
                      {/* 확장 버튼 */}
                      {expandable && (
                        <Td
                          className="px-2 py-4 text-center"
                          onClick={(e: React.MouseEvent) => e.stopPropagation()}
                        >
                          {isRowExpandable(row) ? (
                            <Button
                              onClick={() => handleExpandToggle(rowId)}
                              className="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                              aria-label={expanded ? 'Collapse row' : 'Expand row'}
                            >
                              <Icon
                                name={expanded ? expandIconExpanded : expandIconCollapsed}
                                className="w-4 h-4 text-gray-500 dark:text-gray-400"
                              />
                            </Button>
                          ) : null}
                        </Td>
                      )}

                      {/* 체크박스 */}
                      {selectable && (
                        <Td className="px-4 py-4" onClick={(e: React.MouseEvent) => e.stopPropagation()}>
                          <Checkbox
                            checked={selectedIds.includes(row[idField])}
                            onChange={() => handleSelectRow(row[idField])}
                            className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded"
                          />
                        </Td>
                      )}

                      {/* 데이터 셀 */}
                      {displayColumns.map((column) => (
                        <Td
                          key={column.field}
                          className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-200"
                          style={{ width: column.width, minWidth: column.width }}
                        >
                          {column.cellChildren && column.cellChildren.length > 0
                            ? renderCellChildren(column.cellChildren, row, row[column.field], `${row[idField]}-${column.field}`, __componentContext)
                            : column.render
                              ? column.render(row[column.field], row)
                              : safeRenderValue(row[column.field])}
                        </Td>
                      ))}

                      {/* 액션 메뉴 */}
                      {rowActions && rowActions.length > 0 && (
                        <Td
                          className="px-6 py-4 text-right"
                          onClick={(e: React.MouseEvent) => e.stopPropagation()}
                        >
                          <ActionMenu
                            items={resolveRowActions(row)}
                            triggerLabel=""
                            triggerIconName={IconName.EllipsisHorizontal}
                            position="right"
                          />
                        </Td>
                      )}
                    </Tr>

                    {/* 확장된 행 콘텐츠 */}
                    {expandable && expanded && isRowExpandable(row) && (
                      <Tr className="bg-gray-50 dark:bg-gray-750">
                        <Td
                          colSpan={
                            displayColumns.length +
                            1 + // expand 버튼 컬럼
                            (selectable ? 1 : 0) +
                            (rowActions && rowActions.length > 0 ? 1 : 0)
                          }
                          className="px-6 py-4"
                        >
                          {renderExpandedContent(row)}
                        </Td>
                      </Tr>
                    )}

                    {/* 서브 행 (Sub Row) - 배송비 요약 등 추가 정보 표시용 */}
                    {(subRowChildren && subRowChildren.length > 0 || subRowRender) &&
                      evaluateSubRowCondition(subRowCondition, row) && (
                      <Tr className={subRowClassName ? '!border-t-0' : ''}>
                        <Td
                          colSpan={
                            displayColumns.length +
                            (expandable ? 1 : 0) +
                            (selectable ? 1 : 0) +
                            (rowActions && rowActions.length > 0 ? 1 : 0)
                          }
                          className={subRowClassName ? 'p-2' : subRowClassName || 'px-6 py-2 bg-gray-50 dark:bg-gray-750'}
                        >
                          {subRowClassName ? (
                            <Div className={subRowClassName}>
                              {subRowRender
                                ? subRowRender(row)
                                : renderSubRowChildren(subRowChildren!, row, `subrow-${rowId}`, __componentContext)}
                            </Div>
                          ) : (
                            subRowRender
                              ? subRowRender(row)
                              : renderSubRowChildren(subRowChildren!, row, `subrow-${rowId}`, __componentContext)
                          )}
                        </Td>
                      </Tr>
                    )}
                  </React.Fragment>
                );
              })
            ) : (
              <Tr>
                <Td
                  colSpan={
                    displayColumns.length +
                    (expandable ? 1 : 0) +
                    (selectable ? 1 : 0) +
                    (rowActions && rowActions.length > 0 ? 1 : 0)
                  }
                  className="px-6 py-8 text-center text-gray-500 dark:text-gray-400"
                >
                  {resolvedEmptyMessage}
                </Td>
              </Tr>
            )}
          </Tbody>

          {/* 푸터 행 (Footer Row) - v1.19.0+ */}
          {footerCells && footerCells.length > 0 && paginatedData && paginatedData.length > 0 && (
            <Tfoot>
              <Tr className={footerClassName}>
                {/* 확장 버튼 컬럼 빈 셀 */}
                {expandable && <Td className="px-2 py-4" />}

                {/* 체크박스 컬럼 빈 셀 */}
                {selectable && <Td className="px-4 py-4" />}

                {/* 푸터 셀 렌더링 */}
                {(() => {
                  const footerFieldMap = new Map(
                    footerCells.filter(c => c.field).map(c => [c.field!, c])
                  );
                  return displayColumns.map((column) => {
                    const cell = footerFieldMap.get(column.field);
                    if (!cell) {
                      return <Td key={`footer-${column.field}`} className="px-6 py-4" style={{ width: column.width, minWidth: column.width }} />;
                    }
                    return (
                      <Td
                        key={`footer-${column.field}`}
                        className={`px-6 py-4 text-sm text-gray-900 dark:text-gray-200 ${cell.className || ''}`}
                        colSpan={cell.colSpan}
                        style={{ width: column.width, minWidth: column.width }}
                      >
                        {cell.children && cell.children.length > 0
                          ? renderCellChildren(cell.children, {}, null, `footer-${column.field}`, __componentContext)
                          : cell.text || ''}
                      </Td>
                    );
                  });
                })()}

                {/* 액션 컬럼 빈 셀 */}
                {rowActions && rowActions.length > 0 && <Td className="px-6 py-4" />}
              </Tr>
            </Tfoot>
          )}
        </Table>
      </Div>

      {/* 페이지네이션 */}
      {pagination && (alwaysShowPagination || effectiveTotalPages > 1) && (
        <Div className="flex justify-center mt-4">
          <Pagination
            currentPage={effectiveCurrentPage}
            totalPages={effectiveTotalPages}
            onPageChange={handlePageChange}
            showFirstLast={showFirstLast}
            maxVisiblePages={5}
            prevText={resolvedPrevText}
            nextText={resolvedNextText}
          />
        </Div>
      )}
    </Div>
  );
};
