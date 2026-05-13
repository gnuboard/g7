import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
export interface ActionMenuItem {
    id: string | number;
    label?: string;
    iconName?: IconName;
    onClick?: () => void;
    disabled?: boolean;
    variant?: 'default' | 'danger';
    /** 구분선 여부 (true면 구분선으로 렌더링) */
    divider?: boolean;
    /** 행 데이터의 필드 경로로 disabled 여부를 결정 (값이 falsy면 disabled) */
    disabledField?: string;
    /** 조건부 표시 (false면 아이템 숨김, 엔진이 표현식 평가 후 boolean 전달) */
    if?: boolean;
}
export interface ActionMenuProps {
    items: ActionMenuItem[];
    triggerLabel?: string;
    triggerIconName?: IconName;
    position?: 'left' | 'right';
    className?: string;
    style?: React.CSSProperties;
    /**
     * 커스텀 트리거 콘텐츠
     *
     * children이 제공되면 기본 Button 트리거 대신 children을 클릭 트리거로 사용합니다.
     * 레이아웃 JSON에서 ActionMenu의 children으로 정의한 컴포넌트가 트리거가 됩니다.
     */
    children?: React.ReactNode;
    /**
     * 메뉴 아이템 클릭 시 호출되는 콜백
     * JSON 레이아웃의 actions에서 onItemClick 이벤트로 바인딩됨
     *
     * @param itemId - 클릭된 아이템의 id
     * @param item - 클릭된 아이템 전체 객체
     */
    onItemClick?: (itemId: string | number, item: ActionMenuItem) => void;
}
/**
 * ActionMenu 집합 컴포넌트
 *
 * 드롭다운 형태의 액션 메뉴 컴포넌트입니다.
 * 트리거 버튼과 메뉴 아이템 목록을 제공합니다.
 *
 * 기본 컴포넌트 조합: Button + Div + Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ActionMenu",
 *   "props": {
 *     "triggerLabel": "작업",
 *     "items": [
 *       {"id": 1, "label": "수정", "iconName": "pencil"},
 *       {"id": 2, "label": "삭제", "iconName": "trash", "variant": "danger"}
 *     ]
 *   }
 * }
 */
export declare const ActionMenu: React.FC<ActionMenuProps>;
