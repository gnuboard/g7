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

  const handleItemClick = useCallback((item: MenuItem) => {
    onSelect?.(item);
  }, [onSelect]);

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
  });

  // 트리를 평탄화 — 단일 SortableContext 사용
  const flatItems = useMemo(
    () => flattenTree(localItems, expandedItems),
    [localItems, expandedItems]
  );

  const sortableIds = useMemo(
    () => flatItems.map((f) => f.item.id),
    [flatItems]
  );

  const activeItem = activeId ? findItemById(localItems, Number(activeId)) : null;

  return (
    <Div className={className}>
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
                  isSelected={selectedId === flatItem.item.id}
                  isExpanded={expandedItems.has(flatItem.item.id)}
                  level={flatItem.depth}
                  onClick={() => handleItemClick(flatItem.item)}
                  onToggle={(e) => handleToggleStatus(flatItem.item.id, flatItem.item.is_active, e)}
                  onExpandToggle={() => toggleExpand(flatItem.item.id)}
                  className={itemClassName}
                  selectedClassName={itemSelectedClassName}
                  defaultClassName={itemDefaultClassName}
                  enableDrag={enableDrag}
                  toggleDisabled={toggleDisabled}
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
