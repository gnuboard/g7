import { default as React } from 'react';
import { ActionMenuItem } from './ActionMenu';
import { EditorAttrs } from '../../types';
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
    actions?: any[];
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
    data?: any[];
    cardColumns?: CardGridColumn[];
    gridColumns?: number;
    gap?: number;
    responsiveColumns?: {
        sm?: number;
        md?: number;
        lg?: number;
        xl?: number;
    };
    cardClassName?: string;
    idField?: string;
    pagination?: boolean;
    pageSize?: number;
    serverSidePagination?: boolean;
    serverCurrentPage?: number;
    serverTotalPages?: number;
    alwaysShowPagination?: boolean;
    onPageChange?: (page: number) => void;
    rowActions?: ActionMenuItem[];
    onRowAction?: (actionId: string | number, row: any) => void;
    className?: string;
    style?: React.CSSProperties;
    emptyMessage?: string;
    paginationInfoText?: string;
    prevText?: string;
    nextText?: string;
    showSkeleton?: boolean;
    skeletonCount?: number;
    skeletonCellChildren?: CardGridCellChild[];
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
    __componentContext?: {
        renderEnvRef?: {
            current: Record<string, any> | null;
        };
    };
}
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
export declare const CardGrid: React.FC<CardGridProps>;
