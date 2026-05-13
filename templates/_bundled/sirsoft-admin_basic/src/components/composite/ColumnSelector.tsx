import React, { useState, useRef, useEffect, useMemo } from 'react';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Checkbox } from '../basic/Checkbox';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:ColumnSelector')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:ColumnSelector]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:ColumnSelector]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:ColumnSelector]', ...args),
};

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 전역 window 타입 확장
 * 그누보드7 Core의 AuthManager에 접근하기 위한 타입 선언
 */
declare global {
  interface Window {
    G7Core?: {
      AuthManager?: {
        getInstance: () => {
          getUser: () => { uuid: string; [key: string]: any } | null;
        };
      };
    };
  }
}

export interface ColumnItem {
  field: string;
  header: string;
  required?: boolean;
  visible?: boolean;
}

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

export interface ColumnSelectorProps {
  /** 컴포넌트 고유 ID (레이아웃 JSON의 id 값, localStorage 키 생성에 사용) */
  id?: string;
  columns: ColumnItem[];
  visibleColumns?: string[];
  onColumnVisibilityChange?: (visibleFields: string[]) => void;
  triggerLabel?: string;
  triggerIconName?: IconName;
  position?: 'left' | 'right';
  className?: string;
  style?: React.CSSProperties;
  requiredText?: string;
  /** 드롭다운 메뉴 상단 제목 */
  menuTitle?: string;
  /** 드롭다운 메뉴 상단 설명 */
  menuDescription?: string;
  /** 기본 표시 컬럼 (localStorage에 값이 없을 때 사용) */
  defaultColumns?: string[];
  /** 트리거 버튼에 적용할 추가 className (기존 스타일과 병합됨) */
  triggerClassName?: string;
  /** 라벨 Span에 적용할 추가 className (기존 스타일과 병합됨) */
  labelClassName?: string;
  /**
   * 동적 컬럼 배열 (v1.4.0+)
   *
   * 기존 columns 배열에 동적으로 병합될 컬럼들입니다.
   * dynamicColumnsInsertAfter가 지정된 경우 해당 필드 뒤에 삽입되고,
   * 그렇지 않으면 columns 배열 끝에 추가됩니다.
   */
  dynamicColumns?: ColumnItem[];
  /**
   * 동적 컬럼 삽입 위치를 지정하는 필드명
   * 해당 필드 뒤에 dynamicColumns가 삽입됩니다.
   * 지정하지 않으면 columns 끝에 추가됩니다.
   */
  dynamicColumnsInsertAfter?: string;
}

/**
 * ColumnSelector 집합 컴포넌트
 *
 * 테이블 컬럼의 표시/숨김을 선택할 수 있는 드롭다운 컴포넌트입니다.
 * required 속성이 있는 컬럼은 항상 표시되며 비활성화됩니다.
 *
 * 기본 컴포넌트 조합: Button + Div + Span + Icon + Checkbox
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ColumnSelector",
 *   "props": {
 *     "columns": [
 *       {"field": "id", "header": "$t:admin.users.columns.id", "required": true},
 *       {"field": "name", "header": "$t:admin.users.columns.name", "required": true},
 *       {"field": "email", "header": "$t:admin.users.columns.email"},
 *       {"field": "status", "header": "$t:admin.users.columns.status"}
 *     ],
 *     "visibleColumns": "{{_local.visibleColumns}}",
 *     "triggerLabel": "$t:common.column_selector",
 *     "requiredText": "$t:common.required"
 *   },
 *   "actions": [
 *     {
 *       "event": "onColumnVisibilityChange",
 *       "type": "change",
 *       "handler": "setState",
 *       "params": {
 *         "target": "local",
 *         "visibleColumns": "{{$args[0]}}"
 *       }
 *     }
 *   ]
 * }
 */
export const ColumnSelector: React.FC<ColumnSelectorProps> = ({
  id,
  columns,
  visibleColumns: externalVisibleColumns,
  onColumnVisibilityChange,
  triggerLabel,
  triggerIconName = IconName.Sliders,
  position = 'right',
  className = '',
  style,
  requiredText,
  menuTitle,
  menuDescription,
  defaultColumns,
  triggerClassName,
  labelClassName,
  dynamicColumns,
  dynamicColumnsInsertAfter,
}) => {
  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedTriggerLabel = triggerLabel ?? t('common.column_selector');
  const resolvedRequiredText = requiredText ?? t('common.required');

  // 동적 컬럼 병합
  // dynamicColumns가 있으면 columns에 병합하고, dynamicColumnsInsertAfter 위치에 삽입
  const mergedColumns = useMemo(() => {
    if (!dynamicColumns || !Array.isArray(dynamicColumns) || dynamicColumns.length === 0) {
      return columns;
    }

    // 삽입 위치 찾기
    if (dynamicColumnsInsertAfter) {
      const insertIndex = columns.findIndex((col) => col.field === dynamicColumnsInsertAfter);
      if (insertIndex !== -1) {
        return [
          ...columns.slice(0, insertIndex + 1),
          ...dynamicColumns,
          ...columns.slice(insertIndex + 1),
        ];
      }
    }

    // 삽입 위치가 없으면 끝에 추가
    return [...columns, ...dynamicColumns];
  }, [columns, dynamicColumns, dynamicColumnsInsertAfter]);

  const [isOpen, setIsOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const dropdownMenuRef = useRef<HTMLDivElement>(null);
  const [isInitialized, setIsInitialized] = useState(false);
  const [adjustedPosition, setAdjustedPosition] = useState<'left' | 'right'>(position);

  // 그누보드7 Core의 AuthManager를 통해 현재 로그인한 사용자 ID 가져오기
  // window.G7Core.AuthManager는 런타임에 G7 코어 엔진에서 제공됨
  const userId = useMemo(() => {
    try {
      const authManager = window.G7Core?.AuthManager?.getInstance();
      if (authManager) {
        const user = authManager.getUser();
        return user?.uuid;
      }
      return undefined;
    } catch {
      return undefined;
    }
  }, []);

  // localStorage 키 생성 (g7_columns_{id}_{userId} 형식)
  // id가 없으면 저장 기능 비활성화, userId가 없으면 공유 설정
  const storageKey = id
    ? `g7_columns_${id}${userId !== undefined ? `_${userId}` : ''}`
    : null;

  // localStorage에서 저장된 컬럼 설정 불러오기
  const getStoredColumns = (): string[] | null => {
    if (!storageKey) return null;
    try {
      const stored = localStorage.getItem(storageKey);
      if (stored) {
        const parsed = JSON.parse(stored);
        if (Array.isArray(parsed) && parsed.length > 0) {
          return parsed;
        }
      }
    } catch (error) {
      logger.warn('Failed to load from localStorage:', error);
    }
    return null;
  };

  // localStorage에 컬럼 설정 저장
  const saveToStorage = (cols: string[]) => {
    if (!storageKey) return;
    try {
      localStorage.setItem(storageKey, JSON.stringify(cols));
    } catch (error) {
      logger.warn('Failed to save to localStorage:', error);
    }
  };

  // 초기 컬럼 결정 (우선순위: localStorage > externalVisibleColumns > defaultColumns > 모든 컬럼)
  const getInitialColumns = (): string[] => {
    // 1. localStorage에서 불러오기
    const stored = getStoredColumns();
    if (stored) return stored;

    // 2. 외부에서 전달된 값
    if (externalVisibleColumns && externalVisibleColumns.length > 0) {
      return externalVisibleColumns;
    }

    // 3. 기본 컬럼 설정
    if (defaultColumns && defaultColumns.length > 0) {
      return defaultColumns;
    }

    // 4. visible !== false인 모든 컬럼
    return mergedColumns.filter((col) => col.visible !== false).map((col) => col.field);
  };

  // 표시할 컬럼 관리
  const [internalVisibleColumns, setInternalVisibleColumns] = useState<string[]>(getInitialColumns);

  // 초기 로드 시 결정된 컬럼으로 부모 상태 동기화
  useEffect(() => {
    if (!isInitialized) {
      // 초기 컬럼 값으로 부모 상태 동기화 (localStorage 값 또는 defaultColumns)
      if (onColumnVisibilityChange && internalVisibleColumns.length > 0) {
        onColumnVisibilityChange(internalVisibleColumns);
      }
      setIsInitialized(true);
    }
  }, [isInitialized, onColumnVisibilityChange, internalVisibleColumns]);

  // 외부 visibleColumns가 변경되면 내부 상태 동기화 (단, localStorage 값이 우선)
  useEffect(() => {
    if (isInitialized && externalVisibleColumns && externalVisibleColumns.length > 0) {
      // localStorage에 저장된 값이 없을 때만 외부 값으로 동기화
      const stored = getStoredColumns();
      if (!stored) {
        setInternalVisibleColumns(externalVisibleColumns);
      }
    }
  }, [externalVisibleColumns, isInitialized]);

  // 동적 컬럼이 새로 추가될 때만 visibleColumns에 자동 포함
  // localStorage에 저장된 값이 있으면 사용자의 선택을 존중하고 자동 추가하지 않음
  const prevDynamicFieldsRef = useRef<string[]>([]);
  const hasStoredColumnsRef = useRef<boolean | null>(null);

  useEffect(() => {
    if (!isInitialized) return; // 초기화 전에는 실행하지 않음

    if (dynamicColumns && dynamicColumns.length > 0) {
      const currentDynamicFields = dynamicColumns.map((col) => col.field);

      // 최초 실행 시 localStorage 존재 여부 확인 및 prevDynamicFields 초기화
      if (hasStoredColumnsRef.current === null) {
        const stored = getStoredColumns();
        hasStoredColumnsRef.current = stored !== null;

        // localStorage에 저장된 값이 있으면 현재 동적 필드를 "이미 알려진" 것으로 기록
        // 이렇게 하면 페이지 새로고침 시 동적 컬럼이 다시 자동 추가되지 않음
        if (stored !== null) {
          prevDynamicFieldsRef.current = currentDynamicFields;
          return; // localStorage 값 존중, 자동 추가 안 함
        }
      }

      const prevDynamicFields = prevDynamicFieldsRef.current;

      // 새로 추가된 동적 필드만 찾기 (기존에 없던 필드)
      const trulyNewFields = currentDynamicFields.filter(
        (field) => !prevDynamicFields.includes(field)
      );

      // 새로운 동적 필드가 있을 때만 자동 추가
      if (trulyNewFields.length > 0) {
        const currentVisible = externalVisibleColumns && externalVisibleColumns.length > 0
          ? externalVisibleColumns
          : internalVisibleColumns;

        // 현재 visible에 없는 새 필드만 추가
        const fieldsToAdd = trulyNewFields.filter((field) => !currentVisible.includes(field));

        if (fieldsToAdd.length > 0) {
          const updatedVisible = [...currentVisible, ...fieldsToAdd];
          setInternalVisibleColumns(updatedVisible);
          saveToStorage(updatedVisible);
          onColumnVisibilityChange?.(updatedVisible);
        }
      }

      // 현재 동적 필드 목록 저장
      prevDynamicFieldsRef.current = currentDynamicFields;
    }
  }, [dynamicColumns, isInitialized]); // eslint-disable-line react-hooks/exhaustive-deps

  const visibleColumns = externalVisibleColumns && externalVisibleColumns.length > 0
    ? externalVisibleColumns
    : internalVisibleColumns;

  // 외부 클릭 감지
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen]);

  // 드롭다운 메뉴가 화면을 벗어나는지 감지하고 위치 조정
  useEffect(() => {
    if (isOpen && dropdownMenuRef.current && menuRef.current) {
      const menuRect = dropdownMenuRef.current.getBoundingClientRect();
      const triggerRect = menuRef.current.getBoundingClientRect();
      const viewportWidth = window.innerWidth;

      // 메뉴 너비 (w-64 = 16rem = 256px)
      const menuWidth = menuRect.width || 256;

      if (position === 'right') {
        // right 위치: 메뉴가 버튼의 오른쪽 끝에서 왼쪽으로 펼쳐짐
        // 메뉴의 왼쪽 끝이 화면 왼쪽을 벗어나는지 확인
        const menuLeft = triggerRect.right - menuWidth;
        if (menuLeft < 0) {
          setAdjustedPosition('left');
        } else {
          setAdjustedPosition('right');
        }
      } else {
        // left 위치: 메뉴가 버튼의 왼쪽 끝에서 오른쪽으로 펼쳐짐
        // 메뉴의 오른쪽 끝이 화면 오른쪽을 벗어나는지 확인
        const menuRight = triggerRect.left + menuWidth;
        if (menuRight > viewportWidth) {
          setAdjustedPosition('right');
        } else {
          setAdjustedPosition('left');
        }
      }
    }
  }, [isOpen, position]);

  const handleColumnToggle = (field: string) => {
    const column = mergedColumns.find((col) => col.field === field);
    if (column?.required) return; // required 컬럼은 토글 불가

    const newVisibleColumns = visibleColumns.includes(field)
      ? visibleColumns.filter((f) => f !== field)
      : [...visibleColumns, field];

    setInternalVisibleColumns(newVisibleColumns);
    saveToStorage(newVisibleColumns);
    onColumnVisibilityChange?.(newVisibleColumns);
  };

  const positionClasses = adjustedPosition === 'left' ? 'left-0' : 'right-0';

  return (
    <Div
      ref={menuRef}
      className={`relative inline-block ${className}`}
      style={style}
    >
      {/* 트리거 버튼 */}
      <Button
        onClick={() => setIsOpen(!isOpen)}
        className={G7Core()?.style?.mergeClasses?.(
          'flex items-center gap-2 px-3 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors',
          triggerClassName
        ) ?? `flex items-center gap-2 px-3 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors ${triggerClassName ?? ''}`}
      >
        <Icon name={triggerIconName} className="w-5 h-5" />
        {resolvedTriggerLabel && (
          <Span className={`${labelClassName ? labelClassName : 'hidden sm:inline'} text-sm`}>
            {resolvedTriggerLabel}
          </Span>
        )}
      </Button>

      {/* 드롭다운 메뉴 */}
      {isOpen && (
        <Div
          ref={dropdownMenuRef}
          className={`absolute ${positionClasses} z-50 mt-2 w-64 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg`}
        >
          {/* 헤더 영역 */}
          {(menuTitle || menuDescription) && (
            <Div className="px-4 pt-3 pb-2 border-b border-gray-200 dark:border-gray-700">
              {menuTitle && (
                <Span className="block text-sm font-medium text-gray-900 dark:text-white">
                  {menuTitle}
                </Span>
              )}
              {menuDescription && (
                <Span className="block text-xs text-gray-500 dark:text-gray-400 mt-1">
                  {menuDescription}
                </Span>
              )}
            </Div>
          )}
          <Div className="p-2 max-h-64 overflow-y-auto">
            {mergedColumns.map((col) => {
              const isVisible = visibleColumns.includes(col.field);
              const isRequired = col.required === true;

              return (
                <Div
                  key={col.field}
                  onClick={() => !isRequired && handleColumnToggle(col.field)}
                  className={`flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-gray-700 ${
                    isRequired ? 'cursor-default' : 'cursor-pointer'
                  }`}
                >
                  <Checkbox
                    checked={isVisible}
                    disabled={isRequired}
                    readOnly
                    className="h-4 w-4 text-blue-600 rounded border-gray-300 dark:border-gray-600 pointer-events-none"
                  />
                  <Span className="text-sm text-gray-700 dark:text-gray-300">
                    {col.header}
                  </Span>
                  {isRequired && resolvedRequiredText && (
                    <Span className="ml-auto text-xs text-gray-400 dark:text-gray-500">
                      {resolvedRequiredText}
                    </Span>
                  )}
                </Div>
              );
            })}
          </Div>
        </Div>
      )}
    </Div>
  );
};