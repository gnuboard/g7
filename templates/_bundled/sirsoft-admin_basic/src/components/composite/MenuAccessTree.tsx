import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';
import { ExtensionBadge, ExtensionInfo } from './ExtensionBadge';

// G7Core 전역 객체
const G7Core = (window as any).G7Core;

// useControllableState 훅 타입 정의 (PermissionTree 와 같은 계약)
const useControllableState = G7Core?.useControllableState as
  | (<T>(
      controlledValue: T | undefined,
      defaultValue: T,
      onChange?: (value: T) => void,
      options?: { isEqual?: (a: T, b: T) => boolean }
    ) => [T, (value: T | ((prev: T) => T)) => void])
  | undefined;

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 메뉴 노드 (관리 목록 응답 `menus.data.data` 계층)
 */
export interface MenuAccessNode {
  id: number;
  name: string | Record<string, string>;
  is_active?: boolean;
  extension_type?: string | null;
  extension_identifier?: string | null;
  children?: MenuAccessNode[];
}

export interface MenuAccessTreeProps {
  /** 메뉴 트리 (최상위 + children) */
  data?: MenuAccessNode[];
  /** 선택된 메뉴 ID 배열 */
  value?: number[];
  /** 선택 변경 콜백 (e.target.value = 메뉴 ID 배열) */
  onChange?: (e: { target: { value: number[] } }) => void;
  /** 비활성화 (권한 없음 — 볼 수만 있음) */
  disabled?: boolean;
  /** 전부 체크된 읽기 전용 (관리자 역할) */
  lockedAll?: boolean;
  /** 설치된 모듈 목록 (확장 배지 이름 조회) */
  installedModules?: ExtensionInfo[];
  /** 설치된 플러그인 목록 (확장 배지 이름 조회) */
  installedPlugins?: ExtensionInfo[];
  /** 추가 클래스명 */
  className?: string;
  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/** 순서와 무관하게 같은 ID 집합인지 비교 */
const areIdSetsEqual = (a: number[], b: number[]): boolean => {
  if (a.length !== b.length) return false;
  const set = new Set(a);
  return b.every((id) => set.has(id));
};

/** 다국어 메뉴명을 현재 로케일 문자열로 */
const menuLabel = (name: string | Record<string, string>): string => {
  if (typeof name === 'string') return name;
  const locale = (window as any).G7Core?.locale?.current?.() || 'ko';
  return name[locale] || name.en || Object.values(name)[0] || '';
};

/**
 * MenuAccessTree 집합 컴포넌트
 *
 * 역할 폼 「접근 가능 메뉴」 — 관리자 메뉴 트리를 체크박스로 고른다. 메뉴는 부모도 할당 대상이라
 * PermissionTree(리프만 할당) 모델로는 표현할 수 없어 전용 컴포넌트로 둔다.
 *
 * 부모·자식 규칙(서버와 같다): 자식을 체크하면 부모도 체크되고, 부모를 해제하면 자식도 해제된다.
 * 자식 메뉴는 부모를 거쳐서만 사이드바에 나오므로 따로 두면 보이지 않는 할당이 되기 때문이다.
 * 부모 체크박스는 부모와 자식 전체를 함께 토글하고, 자식이 일부만 골라져 있으면 indeterminate 로 보인다.
 *
 * 트리에 없는 ID(액터가 관리할 수 없는 메뉴)가 value 에 있으면 건드리지 않고 그대로 돌려준다.
 *
 * @example
 * <menu-access-tree data="{{role_menus_tree?.data?.data ?? []}}" value="{{_local.form.menu_ids ?? []}}">
 *   <on type="change"><set-state target="local"><field name="form"><field name="menu_ids" value="{{$event.target.value}}"/></field></set-state></on>
 * </menu-access-tree>
 */
export const MenuAccessTree: React.FC<MenuAccessTreeProps> = ({
  data = [],
  value = [],
  onChange,
  disabled = false,
  lockedAll = false,
  installedModules = [],
  installedPlugins = [],
  className = '',
  id,
  editorAttrs,
}) => {
  const handleChange = useCallback(
    (next: number[]) => {
      if (!onChange) return;
      const event = G7Core?.createChangeEvent
        ? G7Core.createChangeEvent({ value: next })
        : { target: { value: next } };
      onChange(event);
    },
    [onChange]
  );

  // useControllableState 사용 (가능한 경우) 또는 폴백
  const [selected, setSelected] = useControllableState
    ? useControllableState<number[]>(value, [], handleChange, { isEqual: areIdSetsEqual })
    : (() => {
        const [state, setState] = useState<number[]>(value);
        useEffect(() => {
          setState(value);
        }, [value]);
        const wrapped = useCallback(
          (next: number[] | ((prev: number[]) => number[])) => {
            setState((prev) => {
              const resolved = typeof next === 'function' ? next(prev) : next;
              handleChange(resolved);
              return resolved;
            });
          },
          [handleChange]
        );
        return [state, wrapped] as const;
      })();

  const readOnly = disabled || lockedAll;

  // 트리 안 전체 ID · 자식 → 부모 대응
  const { allIds, parentOf } = useMemo(() => {
    const ids: number[] = [];
    const parents: Record<number, number> = {};
    const walk = (nodes: MenuAccessNode[], parentId: number | null) => {
      for (const node of nodes) {
        ids.push(node.id);
        if (parentId !== null) parents[node.id] = parentId;
        if (node.children?.length) walk(node.children, node.id);
      }
    };
    walk(data, null);
    return { allIds: ids, parentOf: parents };
  }, [data]);

  const selectedSet = useMemo(
    () => new Set(lockedAll ? allIds : selected),
    [lockedAll, allIds, selected]
  );

  const selectedInTree = useMemo(
    () => allIds.filter((menuId) => selectedSet.has(menuId)).length,
    [allIds, selectedSet]
  );

  // 펼침 상태 — 자식이 하나라도 골라진 부모는 기본으로 펼치고, 사용자가 연 것·접은 것은 따로 기억한다
  const [opened, setOpened] = useState<Set<number>>(() => new Set());
  const [collapsed, setCollapsed] = useState<Set<number>>(() => new Set());
  const toggleExpand = useCallback((menuId: number, isExpanded: boolean) => {
    const without = (prev: Set<number>) => {
      const next = new Set(prev);
      next.delete(menuId);
      return next;
    };
    const withId = (prev: Set<number>) => new Set(prev).add(menuId);
    if (isExpanded) {
      setOpened(without);
      setCollapsed(withId);
    } else {
      setCollapsed(without);
      setOpened(withId);
    }
  }, []);

  const collectSubtree = useCallback((node: MenuAccessNode): number[] => {
    return [node.id, ...(node.children ?? []).flatMap((child) => collectSubtree(child))];
  }, []);

  const toggleNode = useCallback(
    (node: MenuAccessNode, checked: boolean) => {
      if (readOnly) return;
      setSelected((prev: number[]) => {
        const next = new Set(prev);
        if (checked) {
          // 부모면 자식 전체까지, 자식이면 조상 경로까지 함께 고른다
          collectSubtree(node).forEach((menuId) => next.add(menuId));
          let parentId = parentOf[node.id];
          while (parentId !== undefined) {
            next.add(parentId);
            parentId = parentOf[parentId];
          }
        } else {
          // 해제하면 그 아래 전체가 함께 빠진다
          collectSubtree(node).forEach((menuId) => next.delete(menuId));
        }
        return Array.from(next);
      });
    },
    [readOnly, setSelected, collectSubtree, parentOf]
  );

  const selectAll = useCallback(() => {
    if (readOnly) return;
    setSelected((prev: number[]) => Array.from(new Set([...prev, ...allIds])));
  }, [readOnly, setSelected, allIds]);

  const clearAll = useCallback(() => {
    if (readOnly) return;
    const treeIds = new Set(allIds);
    setSelected((prev: number[]) => prev.filter((menuId) => !treeIds.has(menuId)));
  }, [readOnly, setSelected, allIds]);

  const extensionTypeOf = (node: MenuAccessNode): 'module' | 'plugin' | null => {
    if (node.extension_type === 'module') return 'module';
    if (node.extension_type === 'plugin') return 'plugin';
    return null;
  };

  const renderRow = (node: MenuAccessNode, depth: number) => {
    const children = node.children ?? [];
    const hasChildren = children.length > 0;
    const isChecked = selectedSet.has(node.id);
    const childSelected = children.filter((child) => selectedSet.has(child.id)).length;
    const isPartial = isChecked && hasChildren && childSelected < children.length;
    const isExpanded = opened.has(node.id) || (childSelected > 0 && !collapsed.has(node.id));
    const extensionType = extensionTypeOf(node);
    const inactive = node.is_active === false;

    return (
      <Div key={node.id} data-menu-id={node.id}>
        <Div
          className={`flex items-center gap-3 min-h-[44px] px-3 rounded-lg border transition-colors ${
            isChecked
              ? 'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20'
              : 'border-gray-200 dark:border-gray-700'
          } ${readOnly ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50'}`}
          style={depth > 0 ? { marginLeft: `${depth * 24}px` } : undefined}
          onClick={() => toggleNode(node, !isChecked)}
        >
          <input
            type="checkbox"
            checked={isChecked}
            ref={(el: HTMLInputElement | null) => {
              if (el) el.indeterminate = isPartial;
            }}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
              e.stopPropagation();
              toggleNode(node, e.target.checked);
            }}
            onClick={(e: React.MouseEvent) => e.stopPropagation()}
            disabled={readOnly}
            aria-label={menuLabel(node.name)}
            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
          />
          <Span className={`text-sm font-medium ${inactive ? 'text-gray-400 dark:text-gray-500' : 'text-gray-900 dark:text-white'}`}>
            {menuLabel(node.name)}
          </Span>
          {inactive && (
            <Span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
              {t('admin.roles.form.menus_inactive_badge')}
            </Span>
          )}
          {extensionType && (
            <ExtensionBadge
              type={extensionType}
              identifier={node.extension_identifier ?? undefined}
              installedModules={installedModules}
              installedPlugins={installedPlugins}
            />
          )}
          {hasChildren && (
            <>
              <Span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 ml-auto">
                {childSelected}/{children.length}
              </Span>
              <Button
                type="button"
                onClick={(e: React.MouseEvent) => {
                  e.stopPropagation();
                  toggleExpand(node.id, isExpanded);
                }}
                aria-expanded={isExpanded}
                aria-label={t(isExpanded ? 'common.collapse' : 'common.expand')}
                className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
              >
                <Icon name={isExpanded ? IconName.ChevronDown : IconName.ChevronRight} className="w-4 h-4" />
              </Button>
            </>
          )}
        </Div>
        {hasChildren && isExpanded && (
          <Div className="mt-2 space-y-2">{children.map((child) => renderRow(child, depth + 1))}</Div>
        )}
      </Div>
    );
  };

  // 빈 데이터일 때도 시각적으로 렌더 + 루트에 id/editorAttrs 부착 (편집기 선택 패리티)
  if (data.length === 0) {
    return (
      <Div className={`text-sm text-gray-400 dark:text-gray-500 py-4 text-center ${className}`} id={id} {...editorAttrs}>
        {t('common.no_data')}
      </Div>
    );
  }

  return (
    <Div className={`space-y-3 ${className}`} id={id} {...editorAttrs}>
      <Div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          onClick={selectAll}
          disabled={readOnly}
          className="px-3 h-9 text-sm rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {t('admin.roles.form.menus_select_all')}
        </Button>
        <Button
          type="button"
          onClick={clearAll}
          disabled={readOnly}
          className="px-3 h-9 text-sm rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {t('admin.roles.form.menus_clear_all')}
        </Button>
        <Span className="ml-auto inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200" data-testid="menu-access-count">
          {selectedInTree}/{allIds.length}
        </Span>
      </Div>
      <Div className="space-y-2">{data.map((node) => renderRow(node, 0))}</Div>
    </Div>
  );
};
