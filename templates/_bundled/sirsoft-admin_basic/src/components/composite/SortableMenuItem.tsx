import React from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { Toggle } from './Toggle';

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

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
export const SortableMenuItem: React.FC<SortableMenuItemProps> = ({
  item,
  isSelected = false,
  isExpanded = false,
  level = 0,
  onClick,
  onToggle,
  onExpandToggle,
  className = '',
  selectedClassName = 'bg-blue-50 dark:bg-blue-900/30 border-blue-500',
  defaultClassName = 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600',
  dragHandleClassName = 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300',
  iconContainerClassName = 'bg-gray-100 dark:bg-gray-700',
  contentClassName = '',
  enableDrag = true,
  toggleDisabled = false,
}) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: item.id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  const paddingLeft = level * 24 + 12;

  // 기본 레이아웃 클래스 (변경 불가)
  const baseClassName = 'flex items-center gap-2 cursor-pointer transition-colors';

  // 내부 클래스 (기본 + 상태별 스타일)
  const internalClasses = `${baseClassName} ${isSelected ? selectedClassName : defaultClassName}`;

  // 외부 className과 병합 (외부 클래스가 내부 클래스를 오버라이드 가능)
  const combinedClassName = G7Core()?.style?.mergeClasses?.(internalClasses, className)
    ?? `${internalClasses} ${className}`.trim();

  return (
    <Div
      ref={setNodeRef}
      style={style}
      className={combinedClassName}
      onClick={onClick}
    >
      {/* 드래그 핸들 - enableDrag가 true일 때만 표시 */}
      {enableDrag && (
        <Div
          {...attributes}
          {...listeners}
          className={`flex-shrink-0 cursor-grab active:cursor-grabbing p-1 ${dragHandleClassName}`}
          style={{ paddingLeft: `${paddingLeft}px` }}
        >
          <Icon name="grip-vertical" className="w-4 h-4" />
        </Div>
      )}

      {/* enableDrag가 false일 때 계층 구조용 패딩만 적용 (level > 0인 경우에만) */}
      {!enableDrag && level > 0 && <Div style={{ paddingLeft: `${paddingLeft}px` }} />}

      {/* 펼침/접힘 버튼 - 계층 구조가 있을 때만 표시 */}
      {enableDrag && (
        <>
          {item.hasChildren ? (
            <Button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                onExpandToggle?.();
              }}
              className={`flex-shrink-0 p-1 ${dragHandleClassName}`}
            >
              <Icon
                name={isExpanded ? 'chevron-down' : 'chevron-right'}
                className="w-4 h-4"
              />
            </Button>
          ) : (
            <Div className="flex-shrink-0 w-6" />
          )}
        </>
      )}

      {/* 아이콘 */}
      <Div className={`flex-shrink-0 w-8 h-8 flex items-center justify-center rounded ${iconContainerClassName}`}>
        <Icon name={item.icon || 'file'} className="w-4 h-4 text-gray-600 dark:text-gray-400" />
      </Div>

      {/* 메뉴명 및 URL */}
      <Div className={`flex-1 min-w-0 ${contentClassName}`}>
        <Div className="flex items-center gap-1">
          <Span className="text-sm font-medium text-gray-900 dark:text-white truncate">
            {item.name}
          </Span>
          {item.isModuleMenu && (
            <Icon name="cube" className="w-2 h-2 text-gray-300 dark:text-gray-500 ml-2" title={item.moduleName} />
          )}
        </Div>
        <Span className="text-xs text-gray-500 dark:text-gray-400 truncate block">
          {item.url}
        </Span>
      </Div>

      {/* 토글 스위치 */}
      <Div className="flex-shrink-0" onClick={(e) => e.stopPropagation()}>
        <Toggle
          checked={item.isActive}
          onChange={() => onToggle?.(new MouseEvent('click') as unknown as React.MouseEvent)}
          size="sm"
          disabled={toggleDisabled}
        />
      </Div>
    </Div>
  );
};
