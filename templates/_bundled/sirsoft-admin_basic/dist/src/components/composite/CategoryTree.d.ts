import { default as React } from 'react';
/**
 * 카테고리 노드 인터페이스 (CategoryResource API 응답 기반)
 */
export interface CategoryNode {
    id: number;
    name: string;
    localized_name?: string;
    path?: string;
    products_count?: number;
    children_count?: number;
    children?: CategoryNode[];
}
export interface CategoryTreeProps {
    /** 카테고리 트리 데이터 (CategoryResource API 응답) */
    data?: CategoryNode[];
    /** 펼쳐진 노드 ID 배열 */
    expandedIds?: number[];
    /** 선택된 노드 ID 배열 */
    selectedIds?: number[];
    /** 검색 키워드 (하이라이트용) */
    searchKeyword?: string;
    /** 상품 수 표시 여부 */
    showProductCount?: boolean;
    /** 체크박스 선택 기능 활성화 */
    selectable?: boolean;
    /** 추가 클래스명 */
    className?: string;
    /** 펼치기/접기 콜백: (expandedIds: number[]) => void */
    onToggle?: (expandedIds: number[]) => void;
    /** 선택 변경 콜백: (selectedIds: number[]) => void */
    onSelectionChange?: (selectedIds: number[]) => void;
}
/**
 * CategoryTree 집합 컴포넌트
 *
 * 계층형 카테고리 트리를 렌더링하고 체크박스로 카테고리를 선택할 수 있습니다.
 * expandedIds와 selectedIds를 외부에서 제어하는 Controlled 컴포넌트입니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "CategoryTree",
 *   "props": {
 *     "data": "{{categories?.data?.data ?? []}}",
 *     "expandedIds": "{{_local.categoryExpandedIds ?? []}}",
 *     "selectedIds": "{{_local.categorySearchSelected ?? []}}",
 *     "searchKeyword": "{{_local.categorySearchKeyword ?? ''}}",
 *     "showProductCount": true,
 *     "selectable": true
 *   },
 *   "actions": [
 *     {
 *       "event": "onToggle",
 *       "handler": "setState",
 *       "params": { "target": "_local", "categoryExpandedIds": "{{$args[0]}}" }
 *     },
 *     {
 *       "event": "onSelectionChange",
 *       "handler": "setState",
 *       "params": { "target": "_local", "categorySearchSelected": "{{$args[0]}}" }
 *     }
 *   ]
 * }
 */
export declare const CategoryTree: React.FC<CategoryTreeProps>;
