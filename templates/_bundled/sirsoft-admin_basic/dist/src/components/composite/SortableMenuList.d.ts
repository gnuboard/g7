import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface MenuItem {
    id: number;
    name: string | Record<string, string>;
    slug: string;
    url: string;
    icon: string;
    order: number;
    is_active: boolean;
    parent_id: number | null;
    module_id: number | null;
    module?: {
        id: number;
        name: string | Record<string, string>;
        slug: string;
    } | null;
    children: MenuItem[];
    created_by?: number;
    creator?: {
        id: number;
        name: string;
        email: string;
    } | null;
    roles?: {
        id: number;
        name: string | Record<string, string>;
        permission_type?: string | null;
    }[];
    /** 관리 목록이 싣는 부여 역할 ID (이름은 roleOptions 에서 찾는다) */
    role_ids?: number[];
    created_at?: string;
    updated_at?: string;
}
export interface MenuOrderData {
    parent_menus: {
        id: number;
        order: number;
    }[];
    child_menus: Record<number, {
        id: number;
        order: number;
    }[]>;
    moved_items?: {
        id: number;
        new_parent_id: number | null;
    }[];
}
export interface SortableMenuListProps {
    items: MenuItem[];
    selectedId?: number;
    onSelect?: (item: MenuItem) => void;
    onOrderChange?: (orderData: MenuOrderData) => void;
    onToggleStatus?: (id: number, currentStatus: boolean) => void;
    title?: string;
    className?: string;
    listClassName?: string;
    childrenContainerClassName?: string;
    itemClassName?: string;
    itemSelectedClassName?: string;
    itemDefaultClassName?: string;
    titleClassName?: string;
    enableDrag?: boolean;
    toggleDisabled?: boolean;
    /**
     * 여러 개 선택 모드 (기본값: false). 켜면 항목 앞에 체크박스가 보이고, 끌어 옮기기와 상태
     * 토글이 꺼지며, 항목 클릭은 상세 선택(onSelect) 대신 체크를 토글한다. 자식은 모두 펼친다.
     */
    selectionMode?: boolean;
    /** 선택 모드에서 체크된 메뉴 ID */
    selectedIds?: number[];
    /** 선택 모드에서 체크가 바뀔 때 — 새 ID 배열 */
    onSelectionChange?: (ids: number[]) => void;
    /**
     * 역할 이름표 — 항목의 role_ids 를 이름으로 바꿔 행마다 배지로 보인다. 주지 않으면 배지를 그리지 않는다.
     * 목록에 없는 ID(비활성 역할 등)는 건너뛴다.
     */
    roleOptions?: {
        id: number;
        name: string | Record<string, string>;
    }[];
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/** 평탄화된 트리 아이템 */
interface FlatItem {
    item: MenuItem;
    depth: number;
    parentId: number | null;
}
/**
 * 트리를 평탄 배열로 변환 (확장된 노드의 자식만 포함)
 */
export declare function flattenTree(items: MenuItem[], expandedItems: Set<number>, depth?: number, parentId?: number | null): FlatItem[];
/**
 * 트리에서 아이템 ID로 검색
 */
export declare function findItemById(menuItems: MenuItem[], id: number): MenuItem | null;
/**
 * 아이템의 모든 자손 ID 수집 (순환 참조 방지용)
 */
export declare function collectDescendantIds(item: MenuItem): number[];
/**
 * 트리에서 특정 아이템 제거
 */
export declare function removeItemFromTree(menuItems: MenuItem[], itemId: number): MenuItem[];
/**
 * 특정 부모 아래 지정 위치에 아이템 삽입
 */
export declare function insertItemIntoParent(menuItems: MenuItem[], newItem: MenuItem, targetParentId: number | null, insertIndex: number): MenuItem[];
/**
 * 특정 부모 내에서 자식 순서 변경
 */
export declare function reorderInParent(menuItems: MenuItem[], parentId: number | null, activeId: number, overId: number): MenuItem[];
/**
 * 트리에서 주문 데이터 생성
 */
export declare function generateOrderData(menuItems: MenuItem[]): MenuOrderData;
/**
 * SortableMenuList 컴포넌트
 *
 * 드래그앤드롭 가능한 계층형 메뉴 목록
 * Flattened tree 방식: 단일 SortableContext로 크로스 depth 이동 지원
 */
export declare const SortableMenuList: React.FC<SortableMenuListProps>;
export {};
