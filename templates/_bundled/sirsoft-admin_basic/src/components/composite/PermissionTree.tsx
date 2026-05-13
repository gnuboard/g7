import React, { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { Div } from '../basic/Div';
import { Input } from '../basic/Input';
import { Span } from '../basic/Span';
import { Label } from '../basic/Label';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Accordion } from './Accordion';
import { ExtensionBadge, ExtensionInfo } from './ExtensionBadge';

// G7Core 전역 객체에서 createChangeEvent 가져오기
const G7Core = (window as any).G7Core;

// useControllableState 훅 타입 정의
const useControllableState = G7Core?.useControllableState as
  | (<T>(
      controlledValue: T | undefined,
      defaultValue: T,
      onChange?: (value: T) => void,
      options?: { isEqual?: (a: T, b: T) => boolean }
    ) => [T, (value: T | ((prev: T) => T)) => void])
  | undefined;

/**
 * 권한 값 인터페이스 (id + scope_type)
 */
export interface PermissionValue {
  id: number;
  scope_type: string | null;
}

/**
 * 스코프 옵션 인터페이스
 */
export interface ScopeOption {
  value: string | null;
  label: string;
}

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 스코프 옵션별 설명 다국어 키 매핑
 */
const SCOPE_DESCRIPTION_KEYS: Record<string, string> = {
  '': 'common.scope_type_all_desc',
  self: 'common.scope_type_self_desc',
  role: 'common.scope_type_role_desc',
};

/**
 * 스코프 드롭다운 컴포넌트
 *
 * 네이티브 <select>로는 설명 텍스트를 표시할 수 없으므로
 * 커스텀 드롭다운으로 구현합니다.
 */
const ScopeDropdown: React.FC<{
  options: ScopeOption[];
  value: string | null;
  onChange: (value: string | null) => void;
  disabled?: boolean;
}> = ({ options, value, onChange, disabled = false }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [menuStyle, setMenuStyle] = useState<React.CSSProperties>({});
  const triggerRef = useRef<HTMLDivElement>(null);

  const currentOption = options.find((opt) => opt.value === value) ?? options[0];

  useEffect(() => {
    if (!isOpen) return;

    const handleClickOutside = (e: MouseEvent) => {
      if (triggerRef.current && !triggerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };

    const updatePosition = () => {
      if (!triggerRef.current) return;
      const rect = triggerRef.current.getBoundingClientRect();
      setMenuStyle({
        position: 'fixed',
        top: rect.bottom + 4,
        right: window.innerWidth - rect.right,
        zIndex: 9999,
      });
    };

    document.addEventListener('mousedown', handleClickOutside);
    window.addEventListener('scroll', updatePosition, true);
    window.addEventListener('resize', updatePosition);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      window.removeEventListener('scroll', updatePosition, true);
      window.removeEventListener('resize', updatePosition);
    };
  }, [isOpen]);

  const openMenu = useCallback(() => {
    if (disabled || !triggerRef.current) return;
    const rect = triggerRef.current.getBoundingClientRect();
    setMenuStyle({
      position: 'fixed',
      top: rect.bottom + 4,
      right: window.innerWidth - rect.right,
      zIndex: 9999,
    });
    setIsOpen((prev) => !prev);
  }, [disabled]);

  return (
    <Div ref={triggerRef} className="relative flex-shrink-0">
      <Div
        role="button"
        tabIndex={0}
        onClick={(e: React.MouseEvent) => {
          e.stopPropagation();
          e.preventDefault();
          openMenu();
        }}
        onMouseDown={(e: React.MouseEvent) => e.stopPropagation()}
        onKeyDown={(e: React.KeyboardEvent) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.stopPropagation();
            e.preventDefault();
            openMenu();
          }
          if (e.key === 'Escape') setIsOpen(false);
        }}
        className={`flex items-center gap-1 text-xs px-2 py-1 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 cursor-pointer select-none ${
          disabled ? 'opacity-50 cursor-not-allowed' : 'hover:border-blue-400 dark:hover:border-blue-500'
        }`}
      >
        <Span>{currentOption?.label ?? ''}</Span>
        <Icon name={IconName.ChevronDown} className="w-3 h-3 text-gray-400 dark:text-gray-500" />
      </Div>

      {isOpen && (
        <Div
          style={menuStyle}
          className="w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg py-1"
        >
          {options.map((option) => {
            const optKey = option.value ?? '';
            const descKey = SCOPE_DESCRIPTION_KEYS[optKey];
            const description = descKey ? t(descKey) : '';
            const isSelected = option.value === value;

            return (
              <Div
                key={optKey || '__null__'}
                role="option"
                aria-selected={isSelected}
                onClick={(e: React.MouseEvent) => {
                  e.stopPropagation();
                  e.preventDefault();
                  onChange(option.value);
                  setIsOpen(false);
                }}
                onMouseDown={(e: React.MouseEvent) => e.stopPropagation()}
                className={`px-3 py-2 cursor-pointer transition-colors ${
                  isSelected
                    ? 'bg-blue-50 dark:bg-blue-900/30'
                    : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'
                }`}
              >
                <Div className="flex items-center justify-between">
                  <Span className={`text-xs font-medium ${
                    isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-gray-900 dark:text-white'
                  }`}>
                    {option.label}
                  </Span>
                  {isSelected && (
                    <Icon name={IconName.Check} className="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" />
                  )}
                </Div>
                {description && (
                  <Span className="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    {description}
                  </Span>
                )}
              </Div>
            );
          })}
        </Div>
      )}
    </Div>
  );
};

// PermissionValue 배열 비교 함수 (순서 무관)
const arePermissionValuesEqual = (a: PermissionValue[], b: PermissionValue[]): boolean => {
  if (a.length !== b.length) return false;
  const sortedA = [...a].sort((x, y) => x.id - y.id);
  const sortedB = [...b].sort((x, y) => x.id - y.id);
  return sortedA.every((val, idx) => val.id === sortedB[idx].id && val.scope_type === sortedB[idx].scope_type);
};

/**
 * 권한 노드 인터페이스
 */
export interface PermissionNode {
  id: number;
  identifier: string;
  name: string;
  description?: string;
  module?: string;
  extension_type?: string | null;
  extension_identifier?: string | null;
  is_assignable: boolean;
  leaf_count: number;
  resource_route_key?: string | null;
  owner_key?: string | null;
  children: PermissionNode[];
}

export interface PermissionTreeProps {
  /** 권한 트리 데이터 */
  data?: PermissionNode[];
  /** 선택된 권한 값 배열 [{id, scope_type}] */
  value?: PermissionValue[];
  /** 선택 변경 콜백 */
  onChange?: (e: { target: { value: PermissionValue[] } }) => void;
  /** 스코프 옵션 (드롭다운 선택지) */
  scopeOptions?: ScopeOption[];
  /** 추가 클래스명 */
  className?: string;
  /** 비활성화 여부 */
  disabled?: boolean;
  /** PC에서 권한 목록 열 수 */
  desktopColumns?: 1 | 2 | 3;
  /** 설치된 모듈 목록 (_global.installedModules에서 전달) */
  installedModules?: ExtensionInfo[];
  /** 설치된 플러그인 목록 (_global.installedPlugins에서 전달) */
  installedPlugins?: ExtensionInfo[];
}

/**
 * PermissionTree 집합 컴포넌트
 *
 * 계층형 권한 트리를 렌더링하고 체크박스로 권한을 선택할 수 있습니다.
 * 각 권한에 스코프 타입(전체/소유역할/본인만)을 설정할 수 있는 드롭다운을 제공합니다.
 * 내부적으로 Accordion 컴포넌트를 재사용합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "PermissionTree",
 *   "props": {
 *     "data": "{{permissions?.data?.data?.permissions?.[permType]?.permissions ?? []}}",
 *     "value": "{{_local.form.permission_values ?? []}}",
 *     "scopeOptions": "{{permissions?.data?.data?.scope_options ?? []}}"
 *   },
 *   "actions": [
 *     {
 *       "type": "change",
 *       "handler": "setState",
 *       "params": {
 *         "target": "local",
 *         "form": {
 *           "permission_values": "{{$event.target.value}}"
 *         }
 *       }
 *     }
 *   ]
 * }
 */
export const PermissionTree: React.FC<PermissionTreeProps> = ({
  data = [],
  value = [],
  onChange,
  scopeOptions = [],
  className = '',
  disabled = false,
  desktopColumns = 2,
  installedModules = [],
  installedPlugins = [],
}) => {
  // onChange를 이벤트 객체 형식으로 변환하는 핸들러
  const handleChange = useCallback((newValue: PermissionValue[]) => {
    if (onChange) {
      const event = G7Core?.createChangeEvent
        ? G7Core.createChangeEvent({ value: newValue })
        : { target: { value: newValue } };
      onChange(event);
    }
  }, [onChange]);

  // useControllableState 사용 (가능한 경우) 또는 폴백
  const [selectedValues, setSelectedValues] = useControllableState
    ? useControllableState<PermissionValue[]>(value, [], handleChange, { isEqual: arePermissionValuesEqual })
    : (() => {
        // 폴백: 기존 useState + useEffect 패턴
        const [state, setState] = useState<PermissionValue[]>(value);
        const onChangeRef = React.useRef(onChange);
        onChangeRef.current = onChange;
        const isInternalChangeRef = React.useRef(false);

        useEffect(() => {
          if (isInternalChangeRef.current) {
            isInternalChangeRef.current = false;
            return;
          }
          setState(value);
        }, [value]);

        const wrappedSetState = useCallback((newValue: PermissionValue[] | ((prev: PermissionValue[]) => PermissionValue[])) => {
          isInternalChangeRef.current = true;
          setState((prev) => {
            const nextValue = typeof newValue === 'function' ? newValue(prev) : newValue;
            if (onChangeRef.current) {
              const event = G7Core?.createChangeEvent
                ? G7Core.createChangeEvent({ value: nextValue })
                : { target: { value: nextValue } };
              onChangeRef.current(event);
            }
            return nextValue;
          });
        }, []);

        return [state, wrappedSetState] as const;
      })();

  // 선택된 ID Set (빠른 조회용)
  const selectedIdSet = useMemo(
    () => new Set(selectedValues.map((v) => v.id)),
    [selectedValues]
  );

  /**
   * 노드의 모든 리프(할당 가능한 권한) ID를 재귀적으로 수집
   */
  const getLeafIds = useCallback((node: PermissionNode): number[] => {
    if (node.is_assignable) {
      return [node.id];
    }
    return node.children.flatMap((child) => getLeafIds(child));
  }, []);

  /**
   * 노드의 모든 리프가 선택되었는지 확인
   */
  const isNodeFullySelected = useCallback(
    (node: PermissionNode): boolean => {
      const leafIds = getLeafIds(node);
      return leafIds.length > 0 && leafIds.every((id) => selectedIdSet.has(id));
    },
    [selectedIdSet, getLeafIds]
  );

  /**
   * 노드의 일부 리프가 선택되었는지 확인 (indeterminate 상태)
   */
  const isNodePartiallySelected = useCallback(
    (node: PermissionNode): boolean => {
      const leafIds = getLeafIds(node);
      const selectedCount = leafIds.filter((id) => selectedIdSet.has(id)).length;
      return selectedCount > 0 && selectedCount < leafIds.length;
    },
    [selectedIdSet, getLeafIds]
  );

  /**
   * 노드에서 선택된 리프 개수 반환
   */
  const getSelectedCount = useCallback(
    (node: PermissionNode): number => {
      const leafIds = getLeafIds(node);
      return leafIds.filter((id) => selectedIdSet.has(id)).length;
    },
    [selectedIdSet, getLeafIds]
  );

  /**
   * 개별 권한 체크박스 변경 처리
   */
  const handleLeafChange = useCallback(
    (permissionId: number, checked: boolean) => {
      if (disabled) return;

      setSelectedValues((prev: PermissionValue[]) => {
        return checked
          ? [...prev, { id: permissionId, scope_type: null }]
          : prev.filter((v) => v.id !== permissionId);
      });
    },
    [disabled, setSelectedValues]
  );

  /**
   * 그룹(카테고리/모듈) 체크박스 변경 처리
   * 함수형 업데이트로 항상 최신 상태를 사용
   */
  const handleGroupChange = useCallback(
    (node: PermissionNode, checked: boolean) => {
      if (disabled) return;

      const leafIds = getLeafIds(node);
      const leafIdSet = new Set(leafIds);

      setSelectedValues((prev: PermissionValue[]) => {
        if (checked) {
          // 이미 있는 항목은 유지 (scope_type 보존), 없는 항목만 추가
          const existingIds = new Set(prev.map((v) => v.id));
          const newEntries = leafIds
            .filter((id) => !existingIds.has(id))
            .map((id) => ({ id, scope_type: null as string | null }));
          return [...prev, ...newEntries];
        } else {
          // 해당 그룹의 리프만 제거
          return prev.filter((v) => !leafIdSet.has(v.id));
        }
      });
    },
    [disabled, getLeafIds, setSelectedValues]
  );

  /**
   * 스코프 타입 변경 처리
   */
  const handleScopeTypeChange = useCallback(
    (permissionId: number, scopeType: string | null) => {
      if (disabled) return;

      setSelectedValues((prev: PermissionValue[]) => {
        return prev.map((v) =>
          v.id === permissionId ? { ...v, scope_type: scopeType } : v
        );
      });
    },
    [disabled, setSelectedValues]
  );

  /**
   * 개별 권한 체크박스 렌더링
   */
  const renderLeafPermission = (permission: PermissionNode) => {
    const isChecked = selectedIdSet.has(permission.id);
    const hasResourceRouteKey = !!permission.resource_route_key;
    const showScopeDropdown = isChecked && hasResourceRouteKey && scopeOptions.length > 0;
    const currentScopeType = selectedValues.find((v) => v.id === permission.id)?.scope_type ?? null;

    return (
      <Label
        key={permission.id}
        className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
          isChecked
            ? 'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20'
            : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50'
        } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
      >
        <Input
          type="checkbox"
          checked={isChecked}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
            e.stopPropagation();
            handleLeafChange(permission.id, e.target.checked);
          }}
          disabled={disabled}
          className="w-4 h-4 mt-0.5 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
        />
        <Div className="flex-1 min-w-0">
          <Div className="flex items-center justify-between gap-2">
            <Span className="text-sm font-medium text-gray-900 dark:text-white">
              {permission.name}
            </Span>
            {showScopeDropdown && (
              <ScopeDropdown
                options={scopeOptions}
                value={currentScopeType}
                onChange={(val) => handleScopeTypeChange(permission.id, val)}
                disabled={disabled}
              />
            )}
          </Div>
          {permission.description && (
            <Span className="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              {permission.description}
            </Span>
          )}
        </Div>
      </Label>
    );
  };

  /**
   * 카테고리(2depth) 렌더링
   */
  const renderCategory = (category: PermissionNode, moduleNode: PermissionNode) => {
    const isFullySelected = isNodeFullySelected(category);
    const isPartiallySelected = isNodePartiallySelected(category);
    const selectedCount = getSelectedCount(category);
    const assignableChildren = category.children.filter((p) => p.is_assignable);

    // 하위에 할당 가능한 권한이 없으면 렌더링하지 않음
    if (assignableChildren.length === 0 && category.children.length === 0) {
      return null;
    }

    // 하위에 카테고리가 더 있는 경우 재귀 렌더링
    const hasSubCategories = category.children.some((child) => !child.is_assignable);

    const gridColsClass =
      desktopColumns === 1
        ? 'grid-cols-1'
        : desktopColumns === 3
          ? 'grid-cols-1 md:grid-cols-3'
          : 'grid-cols-1 md:grid-cols-2';

    return (
      <Accordion key={category.id} defaultOpen={false} className="border-0 rounded-none">
        {/* Trigger */}
        <Div slot="trigger" className="flex items-center gap-3 w-full py-2">
          <input
            type="checkbox"
            checked={isFullySelected}
            ref={(el: HTMLInputElement | null) => {
              if (el) el.indeterminate = isPartiallySelected;
            }}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
              e.stopPropagation();
              handleGroupChange(category, e.target.checked);
            }}
            onClick={(e: React.MouseEvent) => e.stopPropagation()}
            onMouseDown={(e: React.MouseEvent) => e.stopPropagation()}
            disabled={disabled}
            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
          />
          <Icon name={IconName.Folder} className="w-4 h-4 text-gray-400 dark:text-gray-500" />
          <Span className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {category.name}
          </Span>
          <Span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 ml-1">
            {selectedCount}/{category.leaf_count}
          </Span>
          <Icon name={IconName.ChevronDown} className="w-4 h-4 text-gray-400 dark:text-gray-500 ml-auto" />
        </Div>

        {/* Content */}
        <Div slot="content" className={`pl-8 grid ${gridColsClass} gap-2 py-2`}>
          {hasSubCategories
            ? // 하위 카테고리가 있으면 재귀 렌더링
              category.children.map((child) =>
                child.is_assignable
                  ? renderLeafPermission(child)
                  : renderCategory(child, moduleNode)
              )
            : // 리프 권한만 있으면 그리드로 렌더링
              assignableChildren.map((permission) => renderLeafPermission(permission))}
        </Div>
      </Accordion>
    );
  };

  /**
   * 확장 타입 판별 (코어, 모듈, 플러그인)
   * API 응답의 extension_type 필드를 우선 사용하고, 없으면 identifier 기반 폴백
   */
  const getExtensionType = (
    node: PermissionNode
  ): 'core' | 'module' | 'plugin' => {
    // API에서 extension_type을 직접 제공하는 경우 우선 사용
    if (node.extension_type === 'core') return 'core';
    if (node.extension_type === 'module') return 'module';
    if (node.extension_type === 'plugin') return 'plugin';

    // 폴백: identifier 기반 판별 (하위 호환)
    const identifier = node.extension_identifier ?? node.module ?? '';
    if (identifier === 'core' || !identifier) return 'core';
    const isPlugin = installedPlugins?.some(
      (p) => p.identifier === identifier
    );
    return isPlugin ? 'plugin' : 'module';
  };

  /**
   * 모듈(1depth) 렌더링
   */
  const renderModule = (moduleNode: PermissionNode) => {
    const isFullySelected = isNodeFullySelected(moduleNode);
    const isPartiallySelected = isNodePartiallySelected(moduleNode);
    const selectedCount = getSelectedCount(moduleNode);
    const extensionType = getExtensionType(moduleNode);

    return (
      <Accordion key={moduleNode.id} defaultOpen={false} className="mb-2">
        {/* Trigger */}
        <Div slot="trigger" className="flex items-center gap-3 w-full py-2">
          <input
            type="checkbox"
            checked={isFullySelected}
            ref={(el: HTMLInputElement | null) => {
              if (el) el.indeterminate = isPartiallySelected;
            }}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
              e.stopPropagation();
              handleGroupChange(moduleNode, e.target.checked);
            }}
            onClick={(e: React.MouseEvent) => e.stopPropagation()}
            onMouseDown={(e: React.MouseEvent) => e.stopPropagation()}
            disabled={disabled}
            className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600"
          />
          <Icon
            name={extensionType === 'core' ? IconName.Cog : IconName.Cube}
            className="w-5 h-5 text-gray-400 dark:text-gray-500"
          />
          <Span className="font-medium text-gray-900 dark:text-white">{moduleNode.name}</Span>
          {extensionType !== 'core' && (
            <ExtensionBadge
              type={extensionType}
              identifier={moduleNode.extension_identifier ?? moduleNode.module}
              installedModules={installedModules}
              installedPlugins={installedPlugins}
            />
          )}
          <Span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 ml-2">
            {selectedCount}/{moduleNode.leaf_count}
          </Span>
          <Icon name={IconName.ChevronDown} className="w-4 h-4 text-gray-400 dark:text-gray-500 ml-auto" />
        </Div>

        {/* Content */}
        <Div slot="content" className="pl-8 space-y-2 pb-2">
          {moduleNode.children.map((category) =>
            category.is_assignable
              ? renderLeafPermission(category)
              : renderCategory(category, moduleNode)
          )}
        </Div>
      </Accordion>
    );
  };

  return (
    <Div className={`space-y-2 ${className}`}>
      {data.map((moduleNode) => renderModule(moduleNode))}
    </Div>
  );
};
