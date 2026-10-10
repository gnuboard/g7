import { default as React } from 'react';
export interface MenuItemData {
    id: number;
    name: string;
    slug: string;
    url: string;
    icon: string;
    isActive: boolean;
    isModuleMenu: boolean;
    moduleName?: string;
    hasChildren?: boolean;
    /** 부여된 역할 이름 — undefined 면 배지 줄을 그리지 않는다, 빈 배열이면 「역할 없음」 */
    roleNames?: string[];
    /**
     * 부여된 역할 수(role_ids 길이). 이름표(활성 역할 목록) 밖 역할 — 비활성 역할이나 역할 관리 권한이
     * 없어 이름을 받지 못한 역할 — 도 접근을 주므로, 이름을 몰라도 「외 n개」·title 의 개수에는 센다.
     * 주지 않으면 roleNames 길이를 쓴다.
     */
    roleCount?: number;
}
export interface SortableMenuItemProps {
    item: MenuItemData;
    isSelected?: boolean;
    isExpanded?: boolean;
    level?: number;
    onClick?: () => void;
    onToggle?: (e: React.MouseEvent) => void;
    onExpandToggle?: () => void;
    /** 아이템 컨테이너 className */
    className?: string;
    /** 선택된 상태 className */
    selectedClassName?: string;
    /** 기본 상태 className */
    defaultClassName?: string;
    /** 드래그 핸들 className */
    dragHandleClassName?: string;
    /** 아이콘 컨테이너 className */
    iconContainerClassName?: string;
    /** 콘텐츠 영역 className */
    contentClassName?: string;
    /** 드래그 핸들 표시 여부 (기본값: true) */
    enableDrag?: boolean;
    /** 토글 비활성화 여부 (기본값: false) */
    toggleDisabled?: boolean;
    /** 선택 모드 — 체크박스를 보이고 상태 토글을 숨긴다 (기본값: false) */
    selectable?: boolean;
    /** 선택 모드의 체크 상태 */
    checked?: boolean;
    /** 선택 모드의 일부 선택(indeterminate) 상태 */
    indeterminate?: boolean;
}
/**
 * SortableMenuItem 컴포넌트
 * 개별 메뉴 아이템 (드래그 가능)
 */
export declare const SortableMenuItem: React.FC<SortableMenuItemProps>;
