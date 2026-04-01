import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
export interface DropdownButtonProps {
    label?: string;
    icon?: IconName | string;
    iconPosition?: 'left' | 'right';
    position?: 'left' | 'right';
    className?: string;
    style?: React.CSSProperties;
    children?: React.ReactNode;
}
/**
 * DropdownButton 집합 컴포넌트
 *
 * 드롭다운 형태의 버튼 컴포넌트입니다.
 * ActionMenu와 유사하지만 children을 통해 자유롭게 메뉴 내용을 구성할 수 있습니다.
 *
 * 기본 컴포넌트 조합: Button + Div + Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "DropdownButton",
 *   "props": {
 *     "label": "더보기",
 *     "icon": "chevron-down",
 *     "iconPosition": "right",
 *     "className": "btn btn-outline btn-sm"
 *   },
 *   "children": [
 *     {
 *       "name": "DropdownItem",
 *       "props": {
 *         "label": "메뉴 1"
 *       }
 *     }
 *   ]
 * }
 */
export declare const DropdownButton: React.FC<DropdownButtonProps>;
