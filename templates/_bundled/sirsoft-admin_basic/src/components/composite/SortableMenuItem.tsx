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

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  G7Core()?.t?.(key, params) ?? key;

// G7Core.t() 번역 함수 참조 — 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다
const tOr = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = (window as any).G7Core?.t?.(key, params);
  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

/** 행에 바로 보이는 역할 배지 수 — 나머지는 「외 n개」 로 접고 전체는 title 로 보인다 */
const MAX_ROLE_BADGES = 3;

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
  selectable = false,
  checked = false,
  indeterminate = false,
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

  // 역할 배지 개수 — 배지로 보이는 이름 뒤에 남은 수(이름 있는 나머지 + 이름표 밖 역할)를 「외 n개」 로 접는다.
  const knownRoleCount = item.roleNames?.length ?? 0;
  const totalRoleCount = Math.max(item.roleCount ?? knownRoleCount, knownRoleCount);
  const unnamedRoleCount = totalRoleCount - knownRoleCount;
  const hiddenRoleCount = totalRoleCount - Math.min(knownRoleCount, MAX_ROLE_BADGES);

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

      {/* 펼침/접힘 버튼 - 계층 구조가 있을 때만 표시. 끌기(enableDrag)와 무관하다 — 읽기 전용(끌기 끔)
          운영자도 하위 메뉴·카테고리를 펼쳐 볼 수 있어야 한다. */}
      {item.hasChildren ? (
        <Button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onExpandToggle?.();
          }}
          className={`flex-shrink-0 p-1 ${dragHandleClassName}`}
          aria-label={isExpanded ? tOr('common.collapse', 'Collapse') : tOr('common.expand', 'Expand')}
          aria-expanded={!!isExpanded}
        >
          <Icon
            name={isExpanded ? 'chevron-down' : 'chevron-right'}
            className="w-4 h-4"
          />
        </Button>
      ) : (
        <Div className="flex-shrink-0 w-6" />
      )}

      {/* 선택 모드 체크박스 — 행 클릭과 같은 토글이라 클릭 전파는 막는다 */}
      {selectable && (
        <input
          type="checkbox"
          checked={checked}
          ref={(el: HTMLInputElement | null) => {
            if (el) el.indeterminate = indeterminate;
          }}
          onChange={() => onClick?.()}
          onClick={(e: React.MouseEvent) => e.stopPropagation()}
          aria-label={item.name}
          className="flex-shrink-0 w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
        />
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
        {Array.isArray(item.roleNames) && (
          <Div
            className="flex flex-wrap items-center gap-1 mt-1"
            data-testid="menu-item-roles"
            title={`${t('admin.menus.list_roles_label')}: ${item.roleNames.join(', ') || t('admin.menus.list_roles_none')}${unnamedRoleCount > 0 ? ` ${t('admin.menus.list_roles_more', { count: unnamedRoleCount })}` : ''}`}
          >
            {item.roleNames.length === 0 ? (
              <Span className="px-1.5 py-0.5 text-[11px] leading-none rounded bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                {t('admin.menus.list_roles_none')}
              </Span>
            ) : (
              <>
                {item.roleNames.slice(0, MAX_ROLE_BADGES).map((roleName) => (
                  <Span
                    key={roleName}
                    className="px-1.5 py-0.5 text-[11px] leading-none rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 truncate max-w-[8rem]"
                  >
                    {roleName}
                  </Span>
                ))}
                {hiddenRoleCount > 0 && (
                  <Span className="text-[11px] leading-none text-gray-500 dark:text-gray-400">
                    {t('admin.menus.list_roles_more', { count: hiddenRoleCount })}
                  </Span>
                )}
              </>
            )}
          </Div>
        )}
      </Div>

      {/* 토글 스위치 — 선택 모드에서는 숨긴다(행 클릭이 선택 토글이라 상태 전환과 섞이지 않게) */}
      {!selectable && (
        <Div className="flex-shrink-0" onClick={(e) => e.stopPropagation()}>
          <Toggle
            checked={item.isActive}
            onChange={() => onToggle?.(new MouseEvent('click') as unknown as React.MouseEvent)}
            size="sm"
            disabled={toggleDisabled}
          />
        </Div>
      )}
    </Div>
  );
};
