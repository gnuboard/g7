import { default as React } from 'react';
export interface SearchSuggestion {
    id: string | number;
    text: string;
}
export interface SearchBarProps {
    name?: string;
    placeholder?: string;
    value?: string;
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    onSubmit?: (e: React.FormEvent<HTMLFormElement>) => void;
    showButton?: boolean;
    suggestions?: SearchSuggestion[];
    onSuggestionClick?: (suggestion: SearchSuggestion) => void;
    showSuggestions?: boolean;
    className?: string;
    style?: React.CSSProperties;
}
/**
 * SearchBar 집합 컴포넌트
 *
 * 검색 입력 필드와 선택적 버튼을 제공하는 검색 바 컴포넌트입니다.
 * Enter 키를 누르면 항상 검색이 실행됩니다.
 *
 * 기본 컴포넌트 조합: Form + Input + Button + Icon + Div + Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (버튼 없음)
 * {
 *   "name": "SearchBar",
 *   "props": {
 *     "placeholder": "검색어를 입력하세요",
 *     "value": "{{query.search}}",
 *     "showButton": false
 *   }
 * }
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (버튼 있음)
 * {
 *   "name": "SearchBar",
 *   "props": {
 *     "placeholder": "검색어를 입력하세요",
 *     "value": "{{query.search}}",
 *     "showButton": true
 *   }
 * }
 */
export declare const SearchBar: React.FC<SearchBarProps>;
