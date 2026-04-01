import { default as React } from 'react';
export interface FilterOption {
    id: string | number;
    label: string;
    value: string | number;
}
export interface Filter {
    id: string;
    label: string;
    type: 'select' | 'checkbox';
    options: FilterOption[];
    value?: string | number | string[] | number[];
}
export interface FilterGroupProps {
    title?: string;
    filters: Filter[];
    onChange?: (filterId: string, value: string | number | string[] | number[] | boolean) => void;
    onReset?: () => void;
    showResetButton?: boolean;
    className?: string;
    style?: React.CSSProperties;
}
/**
 * FilterGroup 집합 컴포넌트
 *
 * 다중 필터 조합을 지원하는 필터 그룹 컴포넌트입니다.
 * Select, Checkbox 타입의 필터들을 조합하여 표시합니다.
 *
 * 기본 컴포넌트 조합: Div + Label + Select + Checkbox + Span + Button
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "FilterGroup",
 *   "props": {
 *     "title": "$t:common.filter",
 *     "filters": [
 *       {
 *         "id": "category",
 *         "label": "카테고리",
 *         "type": "select",
 *         "options": [
 *           {"id": 1, "label": "전자기기", "value": "electronics"},
 *           {"id": 2, "label": "의류", "value": "clothing"}
 *         ]
 *       }
 *     ]
 *   }
 * }
 */
export declare const FilterGroup: React.FC<FilterGroupProps>;
