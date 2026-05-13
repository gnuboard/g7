import { default as React } from 'react';
/**
 * 전역 window 타입 확장
 * 그누보드7 Core의 AuthManager에 접근하기 위한 타입 선언
 */
declare global {
    interface Window {
        G7Core?: {
            AuthManager?: {
                getInstance: () => {
                    getUser: () => {
                        uuid: string;
                        [key: string]: any;
                    } | null;
                };
            };
        };
    }
}
export interface FilterVisibilitySelectorProps {
    /** 컴포넌트 고유 ID (localStorage 키 생성에 사용) */
    id: string;
    /** 현재 선택된 필터 목록 */
    visibleFilters?: string[];
    /** 기본 표시 필터 (localStorage에 값이 없을 때 사용) */
    defaultFilters?: string[];
    /** 필터 가시성 변경 시 호출되는 콜백 */
    onFilterVisibilityChange?: (visibleFilters: string[]) => void;
}
/**
 * FilterVisibilitySelector 집합 컴포넌트
 *
 * 검색 필터의 표시/숨김 설정을 관리하고 localStorage에 저장합니다.
 * 페이지 로드 시 자동으로 localStorage에서 설정을 불러와 부모 컴포넌트에 전달합니다.
 *
 * 이 컴포넌트는 UI를 렌더링하지 않고, 상태 관리 역할만 수행합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "type": "composite",
 *   "name": "FilterVisibilitySelector",
 *   "props": {
 *     "id": "admin_product_list_filters",
 *     "visibleFilters": "{{_local.visibleFilters}}",
 *     "defaultFilters": ["category", "date", "salesStatus", "displayStatus"]
 *   },
 *   "actions": [
 *     {
 *       "event": "onFilterVisibilityChange",
 *       "type": "change",
 *       "handler": "setState",
 *       "params": {
 *         "target": "_local",
 *         "visibleFilters": "{{$args[0]}}"
 *       }
 *     }
 *   ]
 * }
 */
export declare const FilterVisibilitySelector: React.FC<FilterVisibilitySelectorProps>;
export default FilterVisibilitySelector;
