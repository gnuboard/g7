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
    /**
     * 이동 주소 — 있으면 `<a role="menuitem" href>` 로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 된다(#757).
     * 보통 클릭은 지금처럼 onClick(없으면 SPA 이동), 수정키 클릭은 브라우저 기본 동작. 바깥 주소는 새 탭으로 연다.
     */
    href?: string;
    onClick?: (event?: React.MouseEvent) => void;
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
