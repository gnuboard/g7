/**
 * DynamicFieldList 컴포넌트
 *
 * 동적 필드 목록을 관리하는 컴포넌트입니다.
 * @dnd-kit을 사용하여 드래그 앤 드롭 정렬을 지원합니다.
 */

import React, { useCallback, useMemo, useEffect, useRef } from 'react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { Div } from '../../basic/Div';
import { Button } from '../../basic/Button';
import { Icon } from '../../basic/Icon';
import { Span } from '../../basic/Span';
import { DynamicFieldListItem } from './DynamicFieldListItem';
import type { DynamicFieldListProps, DynamicFieldColumn } from './types';

// G7Core 전역 객체 접근
const getG7Core = () => (window as any).G7Core;

/**
 * 고유 ID 생성
 */
const generateId = (): string => {
  return `item_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
};

/**
 * 기본 아이템 생성
 */
const createDefaultItemFromColumns = (columns: DynamicFieldColumn[]): Record<string, unknown> => {
  const item: Record<string, unknown> = {
    _id: generateId(),
  };

  columns.forEach((column) => {
    if (column.type === 'multilingual') {
      const g7Core = getG7Core();
      const locales = g7Core?.locale?.all?.() || ['ko', 'en'];
      const multiValue: Record<string, string> = {};
      locales.forEach((locale: string) => {
        multiValue[locale] = '';
      });
      item[column.key] = multiValue;
    } else if (column.type === 'number') {
      item[column.key] = null;
    } else if (column.type === 'select') {
      item[column.key] = '';
    } else {
      item[column.key] = '';
    }
  });

  return item;
};

/**
 * DynamicFieldList 컴포넌트
 */
/**
 * 에러 객체에서 특정 인덱스의 에러만 추출
 * 예: {"fields.0.name.ko": ["에러"], "fields.1.content.ko": ["에러"]} 에서
 *     index=0 이면 {"name.ko": ["에러"]} 반환
 */
const extractErrorsForIndex = (
  errors: Record<string, string[]> | undefined,
  index: number
): Record<string, string[]> => {
  if (!errors) return {};

  const prefix = `fields.${index}.`;
  const result: Record<string, string[]> = {};

  Object.keys(errors).forEach((key) => {
    if (key.startsWith(prefix)) {
      // "fields.0.name.ko" -> "name.ko"
      const newKey = key.substring(prefix.length);
      result[newKey] = errors[key];
    }
  });

  return result;
};

export const DynamicFieldList: React.FC<DynamicFieldListProps> = ({
  items = [],
  columns,
  onChange,
  onAddItem,
  onRemoveItem,
  onReorder,
  addLabel = '항목 추가',
  enableDrag = true,
  showIndex = true,
  minItems = 0,
  maxItems,
  rowActions,
  emptyMessage = '항목이 없습니다.',
  className = '',
  headerClassName = '',
  rowClassName = '',
  name,
  readOnly = false,
  createDefaultItem,
  childrenKey: _childrenKey,
  itemIdKey = '_id',
  errors,
}) => {
  const formContextRef = useRef<any>(null);

  // normalizedItems ref (항상 최신 참조 — stale closure 방지)
  const normalizedItemsRef = useRef<Record<string, unknown>[]>([]);

  // 리렌더 전 누적 편집분 보관 (빠른 연속 편집 시 이전 수정분 유실 방지)
  const pendingItemsRef = useRef<Record<string, unknown>[] | null>(null);

  /**
   * items 정규화: itemIdKey가 없는 아이템에 안정적인 ID를 부여합니다.
   * 부여된 ID는 onChange를 통해 상태에 반영되므로, 이후 리렌더링에서도 유지됩니다.
   * 이를 통해 setState 라운드트립 후에도 React key가 안정적으로 유지되어
   * 불필요한 리마운트(포커스 손실)를 방지합니다.
   */
  const normalizedItems = useMemo(() => {
    let needsNormalization = false;
    for (const item of items) {
      if (item[itemIdKey] == null) {
        needsNormalization = true;
        break;
      }
    }
    if (!needsNormalization) return items;

    return items.map((item) => {
      if (item[itemIdKey] != null) return item;
      return { ...item, [itemIdKey]: generateId() };
    });
  }, [items, itemIdKey]);

  // normalizedItems 갱신 시 ref 동기화 + pending 초기화
  normalizedItemsRef.current = normalizedItems;
  useEffect(() => {
    pendingItemsRef.current = null;
  }, [normalizedItems]);

  /**
   * 아이템에 대한 안정적인 ID를 반환합니다.
   * normalizedItems에 의해 itemIdKey가 보장되므로, 항상 해당 값을 사용합니다.
   */
  const getStableId = useCallback((item: Record<string, unknown>): string => {
    return String(item[itemIdKey] ?? '');
  }, [itemIdKey]);

  // 폼 컨텍스트 연결
  useEffect(() => {
    if (name) {
      const g7Core = getG7Core();
      if (g7Core?.form) {
        formContextRef.current = g7Core.form;
      }
    }
  }, [name]);

  // 센서 설정
  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  // 아이템 ID 목록 (안정적인 ID 사용)
  const itemIds = useMemo(() => {
    return normalizedItems.map((item) => getStableId(item));
  }, [normalizedItems, getStableId]);

  // 드래그 종료 핸들러
  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event;

      if (over && active.id !== over.id) {
        const currentItems = pendingItemsRef.current ?? normalizedItemsRef.current;
        const currentIds = currentItems.map((item) => String(item[itemIdKey] ?? ''));
        const oldIndex = currentIds.indexOf(String(active.id));
        const newIndex = currentIds.indexOf(String(over.id));

        if (oldIndex !== -1 && newIndex !== -1) {
          const newItems = arrayMove([...currentItems], oldIndex, newIndex);
          pendingItemsRef.current = newItems;
          onChange?.(newItems);
          onReorder?.(newItems);
        }
      }
    },
    [onChange, onReorder, itemIdKey]
  );

  // 아이템 값 변경 핸들러 (pendingItemsRef로 빠른 연속 편집 시 stale closure 방지)
  const handleItemValueChange = useCallback(
    (index: number, key: string, value: unknown) => {
      const currentItems = pendingItemsRef.current ?? normalizedItemsRef.current;
      const newItems = [...currentItems];
      newItems[index] = {
        ...newItems[index],
        [key]: value,
      };
      pendingItemsRef.current = newItems;
      onChange?.(newItems);
    },
    [onChange]
  );

  // 아이템 추가 핸들러
  const handleAddItem = useCallback(() => {
    const currentItems = pendingItemsRef.current ?? normalizedItemsRef.current;
    if (maxItems && currentItems.length >= maxItems) {
      return;
    }

    if (onAddItem) {
      onAddItem();
      return;
    }

    const newItem = createDefaultItem
      ? createDefaultItem()
      : createDefaultItemFromColumns(columns);

    // 고유 ID 확보
    if (!newItem[itemIdKey]) {
      newItem[itemIdKey] = generateId();
    }

    const newItems = [...currentItems, newItem];
    pendingItemsRef.current = newItems;
    onChange?.(newItems);
  }, [maxItems, onAddItem, createDefaultItem, columns, itemIdKey, onChange]);

  // 아이템 삭제 핸들러
  const handleRemoveItem = useCallback(
    (index: number) => {
      const currentItems = pendingItemsRef.current ?? normalizedItemsRef.current;
      if (currentItems.length <= minItems) {
        return;
      }

      const item = currentItems[index];
      if (onRemoveItem) {
        onRemoveItem(index, item);
        return;
      }

      const newItems = currentItems.filter((_, i) => i !== index);
      pendingItemsRef.current = newItems;
      onChange?.(newItems);
    },
    [minItems, onRemoveItem, onChange]
  );

  // 삭제 가능 여부
  const canRemove = normalizedItems.length > minItems;

  // 추가 가능 여부
  const canAdd = !maxItems || normalizedItems.length < maxItems;

  return (
    <Div className={`dynamic-field-list ${className}`}>
      {/* 헤더 */}
      <Div
        className={`flex items-center gap-2 py-2 px-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 rounded-t-lg ${headerClassName}`}
      >
        {/* 드래그 핸들 자리 */}
        {enableDrag && !readOnly && <Div className="flex-shrink-0 w-6" />}

        {/* 순번 헤더 */}
        {showIndex && (
          <Div className="flex-shrink-0 w-8 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
            #
          </Div>
        )}

        {/* 컬럼 헤더 */}
        {columns.map((column) => (
          <Div
            key={column.key}
            className={`flex-1 min-w-0 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase ${column.width || ''}`}
            style={column.width ? { flex: 'none', width: column.width } : {}}
          >
            {column.label}
            {column.required && (
              <Span className="text-red-500 dark:text-red-400 ml-0.5">*</Span>
            )}
          </Div>
        ))}

        {/* 액션 헤더 자리 */}
        {(rowActions || (!readOnly && canRemove)) && (
          <Div className="flex-shrink-0 w-8" />
        )}
      </Div>

      {/* 아이템 목록 */}
      {normalizedItems.length > 0 ? (
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          onDragEnd={handleDragEnd}
        >
          <SortableContext items={itemIds} strategy={verticalListSortingStrategy}>
            <Div className="border-x border-gray-200 dark:border-gray-700">
              {normalizedItems.map((item, index) => {
                const itemId = getStableId(item);
                const itemErrors = extractErrorsForIndex(errors, index);

                return (
                  <DynamicFieldListItem
                    key={itemId}
                    id={itemId}
                    item={item}
                    index={index}
                    columns={columns}
                    enableDrag={enableDrag}
                    showIndex={showIndex}
                    canRemove={canRemove}
                    rowActions={rowActions}
                    readOnly={readOnly}
                    onValueChange={(key, value) => handleItemValueChange(index, key, value)}
                    onRemove={() => handleRemoveItem(index)}
                    className={rowClassName}
                    errors={itemErrors}
                  />
                );
              })}
            </Div>
          </SortableContext>
        </DndContext>
      ) : (
        <Div className="border-x border-gray-200 dark:border-gray-700 py-8 text-center text-gray-500 dark:text-gray-400 text-sm">
          {emptyMessage}
        </Div>
      )}

      {/* 항목 추가 버튼 */}
      {!readOnly && canAdd && (
        <Div className="relative border border-gray-200 dark:border-gray-700 border-t-0 rounded-b-lg">
          {/* 가로줄 중앙 버튼 스타일 */}
          <Div className="flex items-center justify-center py-2">
            <Button
              type="button"
              onClick={handleAddItem}
              className="flex items-center gap-1.5 px-4 py-1.5 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
            >
              <Icon name="plus" className="w-4 h-4" />
              <Span>{addLabel}</Span>
            </Button>
          </Div>
        </Div>
      )}

      {/* 읽기 전용 모드에서 하단 border 처리 */}
      {(readOnly || !canAdd) && normalizedItems.length > 0 && (
        <Div className="border-x border-b border-gray-200 dark:border-gray-700 rounded-b-lg h-1" />
      )}
    </Div>
  );
};

export default DynamicFieldList;
