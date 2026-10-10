import React, { useMemo, useState, useCallback } from 'react';
import { Div } from '../basic/Div';
import { P } from '../basic/P';
import { ActionMenuItem } from './ActionMenu';
import { Pagination } from './Pagination';
import type { EditorAttrs } from '../../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:CardGrid')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:CardGrid]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:CardGrid]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:CardGrid]', ...args),
};

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * CardGrid 셀 자식 요소 타입
 */
export interface CardGridCellChild {
  id?: string;
  type: 'basic' | 'composite';
  name: string;
  props?: Record<string, any>;
  text?: string;
  children?: CardGridCellChild[];
  condition?: string;
  if?: string;
  iteration?: {
    source: string;
    item_var?: string;
    index_var?: string;
  };
  actions?: any[]; // JSON에서 정의된 actions 배열 (ActionMenu용)
}

/**
 * 카드 컬럼 정의 (DataGrid의 컬럼 패턴과 동일)
 */
export interface CardGridColumn {
  id: string;
  cellChildren?: CardGridCellChild[];
}

/**
 * CardGrid Props 인터페이스
 */
export interface CardGridProps {
  // 필수 props
  data?: any[];

  // 카드 컬럼 정의 (DataGrid의 columns 패턴)
  cardColumns?: CardGridColumn[];

  // 그리드 레이아웃
  gridColumns?: number;
  gap?: number;

  // 반응형 설정
  responsiveColumns?: {
    sm?: number;
    md?: number;
    lg?: number;
    xl?: number;
  };

  // 카드 스타일
  cardClassName?: string;

  // 행 ID 필드
  idField?: string;

  // 페이지네이션
  pagination?: boolean;
  pageSize?: number;
  serverSidePagination?: boolean;
  serverCurrentPage?: number;
  serverTotalPages?: number;
  alwaysShowPagination?: boolean;
  onPageChange?: (page: number) => void;

  // 행 액션 메뉴 (DataGrid 패턴)
  rowActions?: ActionMenuItem[];
  onRowAction?: (actionId: string | number, row: any) => void;

  // 기타
  className?: string;
  style?: React.CSSProperties;

  // 다국어 텍스트
  emptyMessage?: string;

  // 페이지네이션 정보
  paginationInfoText?: string;

  // 페이지네이션 텍스트 (다국어 지원)
  prevText?: string;
  nextText?: string;

  // 스켈레톤 관련 props
  showSkeleton?: boolean;              // 스켈레톤 표시 여부 (기본값: true)
  skeletonCount?: number;              // 스켈레톤 개수 (기본값: gridColumns)
  skeletonCellChildren?: CardGridCellChild[];  // 커스텀 스켈레톤 정의

  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;

  /**
   * 엔진이 자동 주입하는 부모 컴포넌트 컨텍스트
   *
   * `renderEnvRef.current` 는 이 CardGrid 를 그린 렌더러의 렌더 환경(폭·언어·dispatcher·레지스트리·
   * 편집 모드)이다. 셀을 그릴 때 이 값을 넘겨, 레이아웃 편집기 미리보기에서 셀이 브라우저 창 폭·
   * 호스트 언어가 아니라 미리보기 값을 따르게 한다(#141).
   *
   * @internal 템플릿 엔진 내부 사용
   */
  __componentContext?: { renderEnvRef?: { current: Record<string, any> | null } };
}

/**
 * G7Core에서 전체 컴포넌트 맵을 가져옵니다.
 * 템플릿에 등록된 모든 컴포넌트를 자동으로 사용할 수 있습니다.
 */
const getComponentMap = (): Record<string, React.ComponentType<any>> => {
  const G7Core = (window as any).G7Core;
  return G7Core?.getComponentMap?.() || {};
};

/**
 * 조건 문자열을 평가합니다.
 *
 * 판정은 엔진(`G7Core.evaluateCondition`)에 위임합니다. 종전에는 이 파일이
 * `new Function('row', ...)` 로 자체 평가기를 두어 엔진과 판정이 갈렸고,
 * `_local`/`_global` 을 참조하는 조건에서 예외가 나면 여기서는 `false`(자식이 사라짐),
 * DataGrid 에서는 `true`(항상 표시)로 **반대 방향** 결과가 났습니다.
 */
const evaluateCondition = (condition: string, row: any): boolean => {
  if (!condition) return true;

  const G7Core = (window as any).G7Core;
  if (typeof G7Core?.evaluateCondition === 'function') {
    return G7Core.evaluateCondition(condition, { row, item: row, $item: row });
  }

  logger.warn('G7Core.evaluateCondition 미노출 — 조건을 평가할 수 없습니다:', condition);
  return true;
};

/**
 * ActionMenu의 actions를 처리하여 onClick 함수를 생성합니다.
 * rowActions prop이 있으면 우선 사용합니다 (DataGrid 패턴).
 */
const processActionMenuActions = (
  child: CardGridCellChild,
  row: any,
  context: Record<string, any>,
  rowActions?: ActionMenuItem[],
  onRowActionHandler?: (actionId: string | number, row: any) => void
): CardGridCellChild => {
  const G7Core = (window as any).G7Core;

  // ActionMenu인 경우
  if (child.name === 'ActionMenu') {
    // 1. rowActions prop이 있으면 우선 사용 (DataGrid 패턴)
    if (rowActions && rowActions.length > 0 && onRowActionHandler) {
      return {
        ...child,
        props: {
          ...child.props,
          items: rowActions.map((action) => ({
            ...action,
            onClick: () => onRowActionHandler(action.id, row),
          })),
        },
      };
    }

    // 2. actions 배열이 있는 경우 (기존 패턴)
    if (child.actions && Array.isArray(child.actions)) {
      const items = child.props?.items || [];
      const actionsMap = new Map(child.actions.map((action: any) => [action.id, action]));

      // items의 onClick을 실제 함수로 변환
      const processedItems = items.map((item: any) => {
        // onClick이 문자열(액션 ID)인 경우
        if (typeof item.onClick === 'string') {
          const actionId = item.onClick;
          const actionDef = actionsMap.get(actionId);

          // 실행은 코어 공개 API `G7Core.dispatch` 로 한다. 카드 행 컨텍스트(row·$value·index)를
          // 바인딩 데이터로 넘겨 `{{row.id}}` 같은 표현식이 그 카드의 행으로 풀리게 한다.
          if (actionDef && typeof G7Core?.dispatch === 'function') {
            return {
              ...item,
              onClick: () => {
                G7Core.dispatch(actionDef, { componentContext: { data: { ...context } } });
              },
            };
          }
        }
        return item;
      });

      // 처리된 items로 child 복사본 반환
      return {
        ...child,
        props: {
          ...child.props,
          items: processedItems,
        },
      };
    }
  }

  // children이 있으면 재귀적으로 처리
  if (child.children && Array.isArray(child.children)) {
    return {
      ...child,
      children: child.children.map((c) => processActionMenuActions(c, row, context, rowActions, onRowActionHandler)),
    };
  }

  return child;
};

/**
 * cellChildren 배열을 React 요소로 렌더링합니다.
 * DataGrid의 renderCellChildren 패턴을 따릅니다.
 *
 * `renderEnv` 는 렌더 환경(폭·언어·dispatcher·레지스트리·편집 모드)이다. `componentContext` 를
 * 넘기지 않는 이유: 넘기면 셀의 `_local` 이 CardGrid 렌더러의 상태로 병합되고 setState 대상도
 * 바뀐다 — 렌더 환경만 필요하므로 상태를 병합하지 않는 `renderEnv` 통로를 쓴다(#141).
 */
const renderCellChildren = (
  cellChildren: CardGridCellChild[],
  row: any,
  index: number,
  keyPrefix: string = '',
  rowActions?: ActionMenuItem[],
  onRowActionHandler?: (actionId: string | number, row: any) => void,
  renderEnv?: Record<string, any> | null
): React.ReactNode => {
  const G7Core = (window as any).G7Core;

  if (G7Core?.renderItemChildren) {
    // 조건부 렌더링 필터링 (condition 또는 if 속성 지원)
    // 참고: renderItemChildren도 자체적으로 if 조건을 평가하지만,
    // 여기서 미리 필터링하면 불필요한 렌더링을 줄일 수 있음
    const filteredChildren = cellChildren.filter((child) => {
      const conditionExpr = child.condition || child.if;
      if (!conditionExpr) return true;
      const result = evaluateCondition(conditionExpr, row);
      return result;
    });

    // 컨텍스트 생성
    const context = {
      row,
      $value: row,
      index,
    };

    // ActionMenu actions 전처리 (재귀적으로)
    const processedChildren = filteredChildren.map((child) =>
      processActionMenuActions(child, row, context, rowActions, onRowActionHandler)
    );

    try {
      // G7Core에서 전체 컴포넌트 맵 가져오기
      const componentMap = getComponentMap();

      // G7Core 템플릿 엔진으로 렌더링
      return G7Core.renderItemChildren(
        processedChildren,
        context,
        componentMap,
        keyPrefix,
        { renderEnv }
      );
    } catch (error) {
      return (
        <Div className="text-red-600 dark:text-red-400 p-4">
          <P>컴포넌트 로드 실패</P>
          <P className="text-sm">[CardGrid] 데이터를 표시할 수 없습니다.</P>
        </Div>
      );
    }
  }

  return null;
};

/**
 * 개별 CardGridCellChild에 대한 스켈레톤을 생성합니다.
 * 모든 컴포넌트에 대해 동일한 기본 스켈레톤을 생성하여 확장성을 보장합니다.
 *
 * @param child 스켈레톤을 생성할 child 요소
 * @param key React key
 * @returns 생성된 스켈레톤 React 노드
 */
const generateSkeletonForChild = (
  child: CardGridCellChild,
  key: string
): React.ReactNode => {
  // Div만 특수 처리 (레이아웃 구조 유지)
  if (child.name === 'Div' && child.children && child.children.length > 0) {
    const childSkeletons = child.children
      .map((c, idx) => generateSkeletonForChild(c, `${key}-child-${idx}`))
      .filter(Boolean);

    return (
      <Div key={key} className={child.props?.className || ''}>
        {childSkeletons}
      </Div>
    );
  }

  // 모든 컴포넌트는 동일한 기본 스켈레톤 (확장성 보장)
  return <Div key={key} className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-full animate-pulse" />;
};

/**
 * cellChildren 배열에서 스켈레톤 UI를 자동 생성합니다.
 *
 * @param cellChildren 카드 컬럼의 cellChildren 배열
 * @param keyPrefix 고유 키 생성을 위한 접두사
 * @returns 생성된 스켈레톤 React 노드 (cellChildren이 없으면 null)
 */
const generateSkeletonFromCellChildren = (
  cellChildren: CardGridCellChild[],
  keyPrefix: string = 'skeleton'
): React.ReactNode => {
  if (!cellChildren || cellChildren.length === 0) {
    return null;
  }

  return cellChildren
    .map((child, index) => {
      const key = `${keyPrefix}-${child.id || child.name}-${index}`;

      // 조건부 렌더링 요소는 제외
      if (child.condition || child.if) {
        return null;
      }

      // iteration이 있으면 1개만 표시
      if (child.iteration) {
        return generateSkeletonForChild(child, key);
      }

      // 일반 요소 처리
      return generateSkeletonForChild(child, key);
    })
    .filter(Boolean);
};

/**
 * CardGrid 집합 컴포넌트
 *
 * 카드 레이아웃으로 데이터를 표시하는 그리드 컴포넌트입니다.
 * DataGrid의 cellChildren 패턴을 사용하여 카드 내용을 JSON으로 정의할 수 있습니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "type": "composite",
 *   "name": "CardGrid",
 *   "props": {
 *     "data": "{{boards.data.data}}",
 *     "columns": 3,
 *     "gap": 4,
 *     "pagination": true,
 *     "pageSize": 12,
 *     "cellChildren": [
 *       {
 *         "type": "basic",
 *         "name": "Div",
 *         "props": {
 *           "className": "bg-white dark:bg-gray-800 rounded-lg p-6"
 *         },
 *         "children": [
 *           {
 *             "type": "basic",
 *             "name": "H3",
 *             "text": "{{row.name}}"
 *           }
 *         ]
 *       }
 *     ]
 *   }
 * }
 */
export const CardGrid: React.FC<CardGridProps> = ({
  data,
  cardColumns = [],
  gridColumns = 3,
  gap = 4,
  responsiveColumns,
  cardClassName = '',
  idField = 'id',
  pagination = true,
  pageSize = 12,
  serverSidePagination = false,
  serverCurrentPage,
  serverTotalPages,
  alwaysShowPagination = false,
  onPageChange,
  paginationInfoText,
  rowActions,
  onRowAction,
  className = '',
  style,
  emptyMessage,
  prevText,
  nextText,
  showSkeleton = true,
  skeletonCount,
  skeletonCellChildren,
  id,
  editorAttrs,
  __componentContext,
}) => {
  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedEmptyMessage = emptyMessage ?? t('common.no_data');

  // cardColumns의 첫 번째 항목에서 cellChildren 추출
  const cellChildren = cardColumns[0]?.cellChildren || [];
  // 클라이언트 사이드 페이지네이션 상태
  const [currentPage, setCurrentPage] = useState(1);

  // 반응형 상태 구독 - cellChildren 내 responsive 속성 지원을 위해 필요
  // 화면 크기 변경 시 CardGrid가 리렌더링되어 renderItemChildren이 새로운 너비로 재실행됨.
  // 반환 폭은 Provider 폭(편집기 미리보기에서는 기기 폭)이라 셀의 responsive 분기 폭으로 넘긴다(#141).
  const G7Core = (window as any).G7Core;
  // eslint-disable-next-line react-hooks/rules-of-hooks
  const responsive = G7Core?.useResponsive?.();

  // 셀 렌더 환경 — 이 CardGrid 를 그린 렌더러의 환경에 자기 Provider 폭을 얹는다(#141)
  const renderEnv = {
    ...(__componentContext?.renderEnvRef?.current ?? {}),
    ...(typeof responsive?.width === 'number' ? { responsiveWidth: responsive.width } : {}),
  };

  // 서버/클라이언트 페이지 정보
  const effectiveCurrentPage = serverSidePagination ? (serverCurrentPage ?? 1) : currentPage;
  const effectiveTotalPages = serverSidePagination
    ? (serverTotalPages ?? 1)
    : Math.ceil((data?.length ?? 0) / pageSize);

  // 페이지 변경 핸들러
  const handlePageChange = (page: number) => {
    if (serverSidePagination) {
      onPageChange?.(page);
    } else {
      setCurrentPage(page);
    }
  };

  // 행 액션 처리 (DataGrid 패턴 + 템플릿 엔진 이벤트 연동)
  const handleRowAction = useCallback(
    (actionId: string | number, row: any) => {
      // prop으로 전달된 onRowAction 호출
      // DynamicRenderer의 actionDispatcher.bindActionsToProps가
      // 레이아웃 JSON의 actions 배열과 자동 연결함
      // onRowAction 호출 시 $args[0] = actionId, $args[1] = row로 전달됨
      onRowAction?.(actionId, row);
    },
    [onRowAction]
  );

  // 페이지네이션 적용된 데이터
  const paginatedData = useMemo(() => {
    if (!data) return [];
    if (!pagination) return data;
    if (serverSidePagination) return data;

    const startIndex = (currentPage - 1) * pageSize;
    const endIndex = startIndex + pageSize;
    return data.slice(startIndex, endIndex);
  }, [data, pagination, serverSidePagination, currentPage, pageSize]);

  // 반응형 그리드 클래스 생성
  const gridClasses = useMemo(() => {
    const classes = [];

    // 기본 (모바일)
    const smCols = responsiveColumns?.sm ?? 1;
    classes.push(`grid-cols-${smCols}`);

    // sm (640px+)
    if (responsiveColumns?.sm) {
      classes.push(`sm:grid-cols-${responsiveColumns.sm}`);
    }

    // md (768px+)
    const mdCols = responsiveColumns?.md ?? Math.min(gridColumns, 2);
    classes.push(`md:grid-cols-${mdCols}`);

    // lg (1024px+)
    const lgCols = responsiveColumns?.lg ?? gridColumns;
    classes.push(`lg:grid-cols-${lgCols}`);

    // xl (1280px+)
    if (responsiveColumns?.xl) {
      classes.push(`xl:grid-cols-${responsiveColumns.xl}`);
    }

    return classes.join(' ');
  }, [gridColumns, responsiveColumns]);

  // 스켈레톤 UI 생성 (useMemo로 최적화)
  const skeletonUI = useMemo(() => {
    if (!showSkeleton) {
      return null;
    }

    // 1. skeletonCellChildren이 있으면 우선 사용
    if (skeletonCellChildren && skeletonCellChildren.length > 0) {
      return generateSkeletonFromCellChildren(skeletonCellChildren, 'custom-skeleton');
    }

    // 2. cellChildren 기반 자동 생성
    if (cellChildren && cellChildren.length > 0) {
      return generateSkeletonFromCellChildren(cellChildren, 'auto-skeleton');
    }

    // 3. cellChildren도 없으면 null (빈 카드)
    return null;
  }, [showSkeleton, skeletonCellChildren, cellChildren]);

  // 로딩 상태 (data가 undefined/null)
  if (!data) {
    // showSkeleton이 false면 빈 화면
    if (!showSkeleton) {
      return null;
    }

    const effectiveSkeletonCount = skeletonCount ?? gridColumns;

    return (
      <Div className={`grid ${gridClasses} gap-${gap} ${className}`} style={style} id={id} {...editorAttrs}>
        {Array.from({ length: effectiveSkeletonCount }).map((_, index) => (
          <Div
            key={`skeleton-${index}`}
            className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6 space-y-4"
          >
            {skeletonUI}
          </Div>
        ))}
      </Div>
    );
  }

  // 빈 데이터 상태
  if (paginatedData.length === 0) {
    return (
      <Div className={className} style={style} id={id} {...editorAttrs}>
        <Div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-12">
          <P className="text-center text-gray-600 dark:text-gray-400">
            {resolvedEmptyMessage}
          </P>
        </Div>
      </Div>
    );
  }

  // 페이지네이션 표시 여부
  const showPagination =
    pagination &&
    (alwaysShowPagination || effectiveTotalPages > 1);

  return (
    <Div className={className} style={style} id={id} {...editorAttrs}>
      {/* 카드 그리드 */}
      <Div className={`grid ${gridClasses} gap-${gap}`}>
        {paginatedData.map((row, index) => (
          <Div key={row[idField] || index} className={cardClassName}>
            {renderCellChildren(cellChildren, row, index, `card-${index}`, rowActions, handleRowAction, renderEnv)}
          </Div>
        ))}
      </Div>

      {/* 페이지네이션 */}
      {showPagination && (
        <Div className="mt-6 space-y-4 flex flex-col items-center">
          <Pagination
            currentPage={effectiveCurrentPage}
            totalPages={effectiveTotalPages}
            onPageChange={handlePageChange}
            showFirstLast={false}
            maxVisiblePages={5}
            prevText={prevText}
            nextText={nextText}
          />
          {/* 페이지네이션 정보 */}
          {paginationInfoText && (
            <Div className="text-sm text-gray-600 dark:text-gray-400 text-center">
              {paginationInfoText}
            </Div>
          )}
        </Div>
      )}
    </Div>
  );
};