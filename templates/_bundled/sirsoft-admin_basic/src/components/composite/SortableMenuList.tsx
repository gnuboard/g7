import React, { useState, useCallback, useMemo, useRef } from 'react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
  DragStartEvent,
  DragOverlay,
  UniqueIdentifier,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { SortableMenuItem, MenuItemData } from './SortableMenuItem';
import { Div } from '../basic/Div';
import { H3 } from '../basic/H3';
import type { EditorAttrs } from '../../types';

// G7Core 전역 객체 접근
const getG7Core = () => (window as any).G7Core;

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
  parent_menus: { id: number; order: number }[];
  child_menus: Record<number, { id: number; order: number }[]>;
  moved_items?: { id: number; new_parent_id: number | null }[];
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
  roleOptions?: { id: number; name: string | Record<string, string> }[];
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
export function flattenTree(items: MenuItem[], expandedItems: Set<number>, depth = 0, parentId: number | null = null): FlatItem[] {
  const result: FlatItem[] = [];
  for (const item of items) {
    result.push({ item, depth, parentId });
    if (item.children?.length > 0 && expandedItems.has(item.id)) {
      result.push(...flattenTree(item.children, expandedItems, depth + 1, item.id));
    }
  }
  return result;
}

/**
 * 트리에서 아이템 ID로 검색
 */
export function findItemById(menuItems: MenuItem[], id: number): MenuItem | null {
  for (const item of menuItems) {
    if (item.id === id) return item;
    if (item.children) {
      const found = findItemById(item.children, id);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 아이템의 모든 자손 ID 수집 (순환 참조 방지용)
 */
export function collectDescendantIds(item: MenuItem): number[] {
  const ids: number[] = [];
  if (item.children) {
    for (const child of item.children) {
      ids.push(child.id);
      ids.push(...collectDescendantIds(child));
    }
  }
  return ids;
}

/**
 * 트리에서 특정 아이템 제거
 */
export function removeItemFromTree(menuItems: MenuItem[], itemId: number): MenuItem[] {
  return menuItems
    .filter((item) => item.id !== itemId)
    .map((item) => {
      if (item.children?.length > 0) {
        return { ...item, children: removeItemFromTree(item.children, itemId) };
      }
      return item;
    });
}

/**
 * 특정 부모 아래 지정 위치에 아이템 삽입
 */
export function insertItemIntoParent(
  menuItems: MenuItem[],
  newItem: MenuItem,
  targetParentId: number | null,
  insertIndex: number
): MenuItem[] {
  if (targetParentId === null) {
    const result = [...menuItems];
    result.splice(insertIndex, 0, newItem);
    return result;
  }

  return menuItems.map((item) => {
    if (item.id === targetParentId) {
      const newChildren = [...(item.children || [])];
      newChildren.splice(insertIndex, 0, newItem);
      return { ...item, children: newChildren };
    }
    if (item.children?.length > 0) {
      return { ...item, children: insertItemIntoParent(item.children, newItem, targetParentId, insertIndex) };
    }
    return item;
  });
}

/**
 * 특정 부모 내에서 자식 순서 변경
 */
export function reorderInParent(
  menuItems: MenuItem[],
  parentId: number | null,
  activeId: number,
  overId: number
): MenuItem[] {
  if (parentId === null) {
    const oldIndex = menuItems.findIndex((i) => i.id === activeId);
    const newIndex = menuItems.findIndex((i) => i.id === overId);
    if (oldIndex === -1 || newIndex === -1) return menuItems;
    return arrayMove(menuItems, oldIndex, newIndex);
  }

  return menuItems.map((item) => {
    if (item.id === parentId && item.children) {
      const oldIndex = item.children.findIndex((c) => c.id === activeId);
      const newIndex = item.children.findIndex((c) => c.id === overId);
      if (oldIndex === -1 || newIndex === -1) return item;
      return { ...item, children: arrayMove(item.children, oldIndex, newIndex) };
    }
    if (item.children?.length > 0) {
      return { ...item, children: reorderInParent(item.children, parentId, activeId, overId) };
    }
    return item;
  });
}

/**
 * 트리에서 주문 데이터 생성
 */
export function generateOrderData(menuItems: MenuItem[]): MenuOrderData {
  const parentMenus = menuItems.map((item, index) => ({
    id: item.id,
    order: index + 1,
  }));

  const childMenus: Record<number, { id: number; order: number }[]> = {};
  const collectChildMenus = (items: MenuItem[]) => {
    items.forEach((parent) => {
      if (parent.children?.length > 0) {
        childMenus[parent.id] = parent.children.map((child, index) => ({
          id: child.id,
          order: index + 1,
        }));
        collectChildMenus(parent.children);
      }
    });
  };
  collectChildMenus(menuItems);

  return { parent_menus: parentMenus, child_menus: childMenus };
}

/**
 * SortableMenuList 컴포넌트
 *
 * 드래그앤드롭 가능한 계층형 메뉴 목록
 * Flattened tree 방식: 단일 SortableContext로 크로스 depth 이동 지원
 */
export const SortableMenuList: React.FC<SortableMenuListProps> = ({
  items = [],
  selectedId,
  onSelect,
  onOrderChange,
  onToggleStatus,
  title,
  className = '',
  listClassName = '',
  childrenContainerClassName = 'bg-gray-50 dark:bg-gray-900/50',
  itemClassName = 'py-2.5 px-3 mx-4 my-2 rounded-lg border',
  itemSelectedClassName,
  itemDefaultClassName,
  titleClassName = 'px-4 py-3 border-b border-gray-200 dark:border-gray-700',
  enableDrag = true,
  toggleDisabled = false,
  selectionMode = false,
  selectedIds = [],
  onSelectionChange,
  roleOptions,
  id,
  editorAttrs,
}) => {
  const [expandedItems, setExpandedItems] = useState<Set<number>>(new Set());
  const [activeId, setActiveId] = useState<UniqueIdentifier | null>(null);
  const [localItems, setLocalItems] = useState<MenuItem[]>(items);
  const isPendingRef = useRef(false);
  const prevOrderKeyRef = useRef<string>('');
  const prevDataKeyRef = useRef<string>('');

  const getDataKey = useCallback((menuItems: MenuItem[]): string => {
    return JSON.stringify(menuItems);
  }, []);

  const getOrderKey = useCallback((menuItems: MenuItem[]): string => {
    return menuItems
      .map((item) => {
        const childrenKeys = item.children?.map((c) => c.id).join(',') || '';
        return `${item.id}:${childrenKeys}`;
      })
      .join('|');
  }, []);

  React.useEffect(() => {
    const newOrderKey = getOrderKey(items);
    const newDataKey = getDataKey(items);

    if (isPendingRef.current) {
      const localOrderKey = getOrderKey(localItems);
      if (newOrderKey === localOrderKey || newOrderKey !== prevOrderKeyRef.current) {
        isPendingRef.current = false;
        prevOrderKeyRef.current = newOrderKey;
        prevDataKeyRef.current = newDataKey;
        if (newOrderKey !== localOrderKey || newDataKey !== getDataKey(localItems)) {
          setLocalItems(items);
        }
      }
      return;
    }

    if (newDataKey !== prevDataKeyRef.current) {
      prevOrderKeyRef.current = newOrderKey;
      prevDataKeyRef.current = newDataKey;
      setLocalItems(items);
    }
  }, [items, getOrderKey, getDataKey, localItems]);

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 8 },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  const getMenuLabel = useCallback((name: string | Record<string, string>): string => {
    if (typeof name === 'string') return name;
    const g7Core = getG7Core();
    const locale = g7Core?.locale?.current?.() || 'ko';
    return name[locale] || name.en || Object.values(name)[0] || '';
  }, []);

  const toggleExpand = useCallback((itemId: number) => {
    setExpandedItems((prev) => {
      const newSet = new Set(prev);
      if (newSet.has(itemId)) {
        newSet.delete(itemId);
      } else {
        newSet.add(itemId);
      }
      return newSet;
    });
  }, []);

  const selectedSet = useMemo(() => new Set(selectedIds ?? []), [selectedIds]);

  // 역할 ID → 이름 (현재 로케일). roleOptions 가 없으면 배지를 그리지 않는다.
  const roleNameById = useMemo(() => {
    // 빈 배열은 「역할 목록 아직 안 옴」 이다(데이터소스 fallback) — 관리자 역할은 늘 있으므로 실제로 빈 경우는 없다
    if (!Array.isArray(roleOptions) || roleOptions.length === 0) return null;
    const map = new Map<number, string>();
    roleOptions.forEach((role) => map.set(Number(role.id), getMenuLabel(role.name)));

    return map;
  }, [roleOptions, getMenuLabel]);

  // 「역할 없음」 은 role_ids 가 정말 비었을 때만 보인다. 이름표(활성 역할 목록) 밖 ID 는 이름 없이 개수(roleCount)로만 센다.
  // 이름표가 아직 안 왔거나(로그인 직후 진입 등)
  // 부여된 역할이 모두 이름표 밖이면 이름을 모르는 것이지 역할이 없는 것이 아니므로 배지 줄을 그리지 않는다.
  const resolveRoleNames = useCallback((item: MenuItem): string[] | undefined => {
    if (!roleNameById || !Array.isArray(item.role_ids)) return undefined;
    if (item.role_ids.length === 0) return [];

    const names = item.role_ids
      .map((roleId) => roleNameById.get(Number(roleId)))
      .filter((name): name is string => !!name);

    return names.length > 0 ? names : undefined;
  }, [roleNameById]);

  // 부모 체크 = 부모와 자손 전체 토글. 자손 일부만 골라져 있으면 indeterminate.
  const subtreeIds = useCallback((item: MenuItem): number[] => [item.id, ...collectDescendantIds(item)], []);

  const toggleSelection = useCallback((item: MenuItem) => {
    const ids = subtreeIds(item);
    const allChecked = ids.every((menuId) => selectedSet.has(menuId));
    const next = new Set(selectedSet);
    ids.forEach((menuId) => (allChecked ? next.delete(menuId) : next.add(menuId)));
    onSelectionChange?.(Array.from(next));
  }, [selectedSet, subtreeIds, onSelectionChange]);

  const handleItemClick = useCallback((item: MenuItem) => {
    if (selectionMode) {
      toggleSelection(item);

      return;
    }
    onSelect?.(item);
  }, [onSelect, selectionMode, toggleSelection]);

  const handleToggleStatus = useCallback((id: number, currentStatus: boolean, e: React.MouseEvent) => {
    e.stopPropagation();
    onToggleStatus?.(id, currentStatus);
  }, [onToggleStatus]);

  const handleDragStart = (event: DragStartEvent) => {
    setActiveId(event.active.id);
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    setActiveId(null);

    if (!over || active.id === over.id) return;

    const activeFlat = flatItems.find((f) => f.item.id === Number(active.id));
    const overFlat = flatItems.find((f) => f.item.id === Number(over.id));

    if (!activeFlat || !overFlat) return;

    if (activeFlat.parentId === overFlat.parentId) {
      // 같은 부모 내 순서 변경
      const newItems = reorderInParent(localItems, activeFlat.parentId, Number(active.id), Number(over.id));
      setLocalItems(newItems);
      isPendingRef.current = true;
      onOrderChange?.(generateOrderData(newItems));
    } else {
      // 크로스 depth 이동
      const activeItem = activeFlat.item;

      // 순환 참조 방지: active를 over의 자손 아래로 이동 차단
      const descendantIds = collectDescendantIds(activeItem);
      if (descendantIds.includes(Number(over.id))) return;

      // 1) 트리에서 active 제거
      let newItems = removeItemFromTree(localItems, activeItem.id);

      // 2) 드래그 방향 판단: flat 배열에서 active가 over보다 아래에 있으면 위로 드래그 중
      const activeIndex = flatItems.findIndex((f) => f.item.id === Number(active.id));
      const overIndex = flatItems.findIndex((f) => f.item.id === Number(over.id));
      const isDraggingUp = activeIndex > overIndex;

      // 3) over가 속한 부모의 자식 목록에서 over의 위치를 찾아 삽입
      //    위로 드래그 → over 앞에 삽입, 아래로 드래그 → over 뒤에 삽입
      const overParentId = overFlat.parentId;
      let insertIndex: number;
      if (overParentId === null) {
        insertIndex = newItems.findIndex((i) => i.id === Number(over.id));
        if (insertIndex === -1) insertIndex = newItems.length;
        else if (!isDraggingUp) insertIndex += 1;
      } else {
        const overParent = findItemById(newItems, overParentId);
        const siblings = overParent?.children || [];
        insertIndex = siblings.findIndex((c) => c.id === Number(over.id));
        if (insertIndex === -1) insertIndex = siblings.length;
        else if (!isDraggingUp) insertIndex += 1;
      }

      const movedItem: MenuItem = {
        ...activeItem,
        parent_id: overParentId,
        children: activeItem.children || [],
      };
      newItems = insertItemIntoParent(newItems, movedItem, overParentId, insertIndex);

      setLocalItems(newItems);
      isPendingRef.current = true;
      const orderData = generateOrderData(newItems);
      orderData.moved_items = [{ id: activeItem.id, new_parent_id: overParentId }];
      onOrderChange?.(orderData);
    }
  };

  const toMenuItemData = (item: MenuItem): MenuItemData => ({
    id: item.id,
    name: getMenuLabel(item.name),
    slug: item.slug,
    url: item.url,
    icon: item.icon,
    isActive: item.is_active,
    isModuleMenu: !!item.module_id,
    moduleName: item.module ? getMenuLabel(item.module.name) : undefined,
    hasChildren: item.children && item.children.length > 0,
    roleNames: resolveRoleNames(item),
    roleCount: Array.isArray(item.role_ids) ? item.role_ids.length : undefined,
  });

  // 선택 모드에서는 자식을 모두 펼친다 — 접힌 자식은 고를 수 없다
  const effectiveExpanded = useMemo(() => {
    if (!selectionMode) return expandedItems;
    const all = new Set<number>();
    const walk = (menuItems: MenuItem[]) => menuItems.forEach((item) => {
      if (item.children?.length > 0) {
        all.add(item.id);
        walk(item.children);
      }
    });
    walk(localItems);

    return all;
  }, [selectionMode, expandedItems, localItems]);

  // 트리를 평탄화 — 단일 SortableContext 사용
  const flatItems = useMemo(
    () => flattenTree(localItems, effectiveExpanded),
    [localItems, effectiveExpanded]
  );

  const sortableIds = useMemo(
    () => flatItems.map((f) => f.item.id),
    [flatItems]
  );

  const activeItem = activeId ? findItemById(localItems, Number(activeId)) : null;

  return (
    <Div className={className} id={id} {...editorAttrs}>
      {title && (
        <Div className={titleClassName}>
          <H3 className="text-sm font-medium text-gray-900 dark:text-white">{title}</H3>
        </Div>
      )}

      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragStart={handleDragStart}
        onDragEnd={handleDragEnd}
      >
        <SortableContext items={sortableIds} strategy={verticalListSortingStrategy}>
          <Div className={listClassName}>
            {flatItems.map((flatItem) => (
              <Div
                key={flatItem.item.id}
                className={flatItem.depth > 0 ? childrenContainerClassName : ''}
              >
                <SortableMenuItem
                  item={toMenuItemData(flatItem.item)}
                  isSelected={!selectionMode && selectedId === flatItem.item.id}
                  isExpanded={effectiveExpanded.has(flatItem.item.id)}
                  level={flatItem.depth}
                  onClick={() => handleItemClick(flatItem.item)}
                  onToggle={(e) => handleToggleStatus(flatItem.item.id, flatItem.item.is_active, e)}
                  onExpandToggle={() => toggleExpand(flatItem.item.id)}
                  className={itemClassName}
                  selectedClassName={itemSelectedClassName}
                  defaultClassName={itemDefaultClassName}
                  enableDrag={enableDrag && !selectionMode}
                  toggleDisabled={toggleDisabled}
                  selectable={selectionMode}
                  {...(selectionMode ? (() => {
                    const ids = subtreeIds(flatItem.item);
                    const count = ids.filter((menuId) => selectedSet.has(menuId)).length;

                    return { checked: count === ids.length, indeterminate: count > 0 && count < ids.length };
                  })() : {})}
                />
              </Div>
            ))}
          </Div>
        </SortableContext>

        <DragOverlay>
          {activeItem ? (
            <Div className="bg-white dark:bg-gray-800 shadow-lg rounded-lg border border-blue-500 p-3">
              <Div className="flex items-center gap-2">
                <Div className="text-sm font-medium text-gray-900 dark:text-white">
                  {getMenuLabel(activeItem.name)}
                </Div>
              </Div>
            </Div>
          ) : null}
        </DragOverlay>
      </DndContext>
    </Div>
  );
};
