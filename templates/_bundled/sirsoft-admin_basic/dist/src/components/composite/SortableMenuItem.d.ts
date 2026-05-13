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
}
/**
 * SortableMenuItem 컴포넌트
 * 개별 메뉴 아이템 (드래그 가능)
 */
export declare const SortableMenuItem: React.FC<SortableMenuItemProps>;
