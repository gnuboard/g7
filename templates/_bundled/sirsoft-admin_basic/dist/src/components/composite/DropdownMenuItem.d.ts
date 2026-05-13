import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
export interface DropdownMenuItemProps {
    label?: string;
    icon?: IconName | string;
    variant?: 'default' | 'danger';
    disabled?: boolean;
    divider?: boolean;
    className?: string;
    style?: React.CSSProperties;
    onClick?: () => void;
}
/**
 * DropdownMenuItem 집합 컴포넌트
 *
 * DropdownButton의 자식으로 사용되는 메뉴 아이템 컴포넌트입니다.
 * ActionMenu의 아이템과 동일한 디자인을 사용합니다.
 *
 * 기본 컴포넌트 조합: Div + Icon + Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "DropdownButton",
 *   "props": {
 *     "label": "작업",
 *     "icon": "chevron-down"
 *   },
 *   "children": [
 *     {
 *       "name": "DropdownMenuItem",
 *       "props": {
 *         "label": "수정",
 *         "icon": "pencil"
 *       },
 *       "actions": [
 *         {
 *           "type": "click",
 *           "handler": "navigate",
 *           "params": {
 *             "path": "/edit/{{id}}"
 *           }
 *         }
 *       ]
 *     },
 *     {
 *       "name": "DropdownMenuItem",
 *       "props": {
 *         "divider": true
 *       }
 *     },
 *     {
 *       "name": "DropdownMenuItem",
 *       "props": {
 *         "label": "삭제",
 *         "icon": "trash",
 *         "variant": "danger"
 *       }
 *     }
 *   ]
 * }
 */
export declare const DropdownMenuItem: React.FC<DropdownMenuItemProps>;
