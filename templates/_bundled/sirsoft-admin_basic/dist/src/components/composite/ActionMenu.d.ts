import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
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
    /** 잠긴 항목에 보일 안내 문구 — 항목 안 보조 줄과 title 로 「왜 잠겼는지」를 알린다(잠기지 않으면 숨김) */
    disabledReason?: string;
    /** 조건부 표시 (false면 아이템 숨김, 엔진이 표현식 평가 후 boolean 전달) */
    if?: boolean;
}
export interface ActionMenuProps {
    items: ActionMenuItem[];
    /** 트리거 글자. 생략하면 공용 문구 `common.actions`(화면 언어), 빈 문자열이면 아이콘만 그린다 */
    triggerLabel?: string;
    triggerIconName?: IconName;
    /**
     * 글자 없는 아이콘 트리거의 접근 가능한 이름 (aria-label)
     *
     * `triggerLabel` 이 비어 아이콘만 그려질 때 쓴다. 주지 않으면 공용 문구 `common.more_actions`(엔진 밖 `More actions`)로
     * 채워 이름 없는 버튼을 남기지 않는다. 보이는 글자가 있으면 그 글자가 이름이므로 쓰지 않는다.
     */
    triggerAriaLabel?: string;
    /** `triggerAriaLabel` 과 같은 뜻(DataGrid 행 메뉴가 열 머리글 이름을 넘긴다). 둘 다 있으면 `triggerAriaLabel` 이 앞선다 */
    ariaLabel?: string;
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
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
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
