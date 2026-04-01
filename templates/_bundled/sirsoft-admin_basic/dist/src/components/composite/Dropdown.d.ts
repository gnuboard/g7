import { default as React } from 'react';
export interface DropdownItem {
    label: string;
    value: string;
    onClick?: () => void;
    disabled?: boolean;
}
export interface DropdownProps {
    label: string;
    items: DropdownItem[];
    /** 아이템 클릭 시 호출되는 콜백. value 문자열을 전달합니다. */
    onItemClick?: (value: string, item: DropdownItem) => void;
    position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right';
    /** 드롭다운 스타일 변형 */
    variant?: 'default' | 'text';
    className?: string;
    style?: React.CSSProperties;
}
/**
 * Dropdown 집합 컴포넌트
 *
 * Button + Div 기본 컴포넌트를 조합하여 드롭다운 메뉴 UI를 구성합니다.
 * 키보드 네비게이션 (Arrow Up/Down, Enter, Escape) 지원, 외부 클릭 감지 기능을 포함합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Dropdown",
 *   "props": {
 *     "label": "작업",
 *     "items": [
 *       {"label": "수정", "value": "edit"},
 *       {"label": "삭제", "value": "delete"}
 *     ]
 *   }
 * }
 */
export declare const Dropdown: React.FC<DropdownProps>;
