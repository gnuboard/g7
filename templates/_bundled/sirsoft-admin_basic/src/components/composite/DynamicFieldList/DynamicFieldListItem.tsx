/**
 * DynamicFieldListItem 컴포넌트
 *
 * 동적 필드 목록의 개별 행을 렌더링합니다.
 * @dnd-kit의 useSortable 훅을 사용하여 드래그 앤 드롭을 지원합니다.
 */

import React, { useCallback } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Div } from '../../basic/Div';
import { Input } from '../../basic/Input';
import { Select } from '../../basic/Select';
import { Textarea } from '../../basic/Textarea';
import { Button } from '../../basic/Button';
import { Icon } from '../../basic/Icon';
import { Span } from '../../basic/Span';
import { useCurrentMultilingualLocale } from '../MultilingualLocaleContext';
import type { DynamicFieldListItemProps, DynamicFieldColumn } from './types';

// G7Core 전역 객체 접근 (에러 처리용)
const getG7Core = () => (window as any).G7Core;

/**
 * 다국어 입력 컴포넌트 (간소화 버전)
 *
 * MultilingualTabPanel 내부에서 사용 시 Context를 통해 로케일을 받습니다.
 * Context 외부에서 사용 시 G7Core.locale.current()를 fallback으로 사용합니다.
 */
const MultilingualCellInput: React.FC<{
  value: Record<string, string> | string;
  onChange: (value: Record<string, string>) => void;
  placeholder?: string;
  readOnly?: boolean;
  disabled?: boolean;
  hasError?: boolean;
}> = ({ value, onChange, placeholder, readOnly, disabled, hasError }) => {
  // Context를 통해 로케일 접근 (Stale Closure 방지)
  const { activeLocale, getActiveLocale } = useCurrentMultilingualLocale();

  // 문자열이면 현재 로케일 객체로 변환
  const multiValue: Record<string, string> =
    typeof value === 'string' ? { [activeLocale]: value } : value || {};

  // Stale Closure 방지: 이벤트 핸들러에서는 getActiveLocale() 사용
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const currentLocale = getActiveLocale();
      onChange({
        ...multiValue,
        [currentLocale]: e.target.value,
      });
    },
    [multiValue, getActiveLocale, onChange]
  );

  // 에러 시 적용할 border 클래스
  const errorBorderClass = hasError
    ? 'border-red-500 dark:border-red-500 focus:ring-red-500 focus:border-red-500'
    : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500';

  // 렌더링에서는 activeLocale 직접 사용 (React 리렌더링 보장)
  return (
    <Input
      type="text"
      value={multiValue[activeLocale] || ''}
      onChange={handleChange}
      placeholder={placeholder}
      readOnly={readOnly}
      disabled={disabled}
      className={`w-full px-3 py-2 text-sm border ${errorBorderClass} rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2`}
    />
  );
};

/**
 * 아이템 필드 기반 조건부 readOnly 평가
 * @param item 아이템 데이터
 * @param condition 조건 문자열 ("!" prefix로 부정 가능, 예: "!is_custom")
 * @returns readOnly 여부
 */
const getReadOnlyFromCondition = (
  item: Record<string, unknown>,
  condition?: string
): boolean => {
  if (!condition) return false;
  const negate = condition.startsWith('!');
  const field = negate ? condition.slice(1) : condition;
  const value = !!item[field];
  return negate ? !value : value;
};

/**
 * 셀 렌더러
 */
const CellRenderer: React.FC<{
  column: DynamicFieldColumn;
  item: Record<string, unknown>;
  index: number;
  onValueChange: (key: string, value: unknown) => void;
  readOnly?: boolean;
  error?: string;
}> = ({ column, item, index, onValueChange, readOnly, error }) => {
  const value = item[column.key];
  const isDisabled = readOnly ||
    column.readOnly ||
    getReadOnlyFromCondition(item, column.readOnlyCondition) ||
    column.disabled;
  const hasError = !!error;

  // 에러 시 적용할 border 클래스
  const errorBorderClass = hasError
    ? 'border-red-500 dark:border-red-500 focus:ring-red-500 focus:border-red-500'
    : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500';

  const handleChange = useCallback(
    (newValue: unknown) => {
      onValueChange(column.key, newValue);
    },
    [column.key, onValueChange]
  );

  // 에러 메시지 래퍼
  const wrapWithError = (inputElement: React.ReactNode) => (
    <Div className="w-full">
      {inputElement}
      {hasError && (
        <Span className="text-xs text-red-500 dark:text-red-400 mt-1 block">
          {error}
        </Span>
      )}
    </Div>
  );

  switch (column.type) {
    case 'input':
      return wrapWithError(
        <Input
          type="text"
          value={(value as string) || ''}
          onChange={(e) => handleChange(e.target.value)}
          placeholder={column.placeholder}
          readOnly={isDisabled}
          disabled={isDisabled}
          className={`w-full px-3 py-2 text-sm border ${errorBorderClass} rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 ${column.className || ''}`}
        />
      );

    case 'number':
      return wrapWithError(
        <Input
          type="number"
          value={(value as number) ?? ''}
          onChange={(e) => handleChange(e.target.value ? Number(e.target.value) : null)}
          placeholder={column.placeholder}
          min={column.min}
          max={column.max}
          step={column.step}
          readOnly={isDisabled}
          disabled={isDisabled}
          className={`w-full px-3 py-2 text-sm border ${errorBorderClass} rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 ${column.className || ''}`}
        />
      );

    case 'select':
      return wrapWithError(
        <Select
          value={(value as string | number) ?? ''}
          onChange={(e) => handleChange(e.target.value)}
          disabled={isDisabled}
          className={`w-full px-3 py-2 text-sm border ${errorBorderClass} rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 ${column.className || ''}`}
        >
          <option value="">선택하세요</option>
          {column.options?.map((opt) => (
            <option key={opt.value} value={opt.value} disabled={opt.disabled}>
              {opt.label}
            </option>
          ))}
        </Select>
      );

    case 'textarea':
      return wrapWithError(
        <Textarea
          value={(value as string) || ''}
          onChange={(e) => handleChange(e.target.value)}
          placeholder={column.placeholder}
          readOnly={isDisabled}
          disabled={isDisabled}
          className={`w-full px-3 py-2 text-sm border ${errorBorderClass} rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 resize-none ${column.className || ''}`}
          rows={2}
        />
      );

    case 'multilingual':
      return wrapWithError(
        <MultilingualCellInput
          value={value as Record<string, string> | string}
          onChange={(val) => handleChange(val)}
          placeholder={column.placeholder}
          readOnly={isDisabled}
          disabled={isDisabled}
          hasError={hasError}
        />
      );

    case 'custom':
      if (column.render) {
        return <>{column.render(item, index, handleChange)}</>;
      }
      return <Span className="text-gray-500 dark:text-gray-400">-</Span>;

    default:
      return <Span className="text-gray-900 dark:text-white text-sm">{String(value ?? '')}</Span>;
  }
};

/**
 * DynamicFieldListItem 컴포넌트
 */
/**
 * 컬럼에 대한 에러 메시지 추출
 * errors 예: {"name.ko": ["에러 메시지"], "content.ko": ["에러 메시지"]}
 * multilingual 타입인 경우: "name.ko" 형식에서 현재 로케일에 맞는 에러 반환
 * 일반 타입인 경우: "name" 키로 에러 반환
 */
const getErrorForColumn = (
  errors: Record<string, string[]> | undefined,
  columnKey: string,
  columnType: string
): string | undefined => {
  if (!errors) return undefined;

  // multilingual 타입인 경우 현재 로케일의 에러를 찾음
  if (columnType === 'multilingual') {
    const g7Core = getG7Core();
    const currentLocale = g7Core?.locale?.current?.() || 'ko';
    const key = `${columnKey}.${currentLocale}`;
    const errorArray = errors[key];
    return errorArray?.[0];
  }

  // 일반 타입인 경우
  const errorArray = errors[columnKey];
  return errorArray?.[0];
};

export const DynamicFieldListItem: React.FC<DynamicFieldListItemProps> = ({
  item,
  index,
  id,
  columns,
  enableDrag = true,
  showIndex = true,
  canRemove = true,
  rowActions,
  readOnly = false,
  onValueChange,
  onRemove,
  className = '',
  level = 0,
  errors,
}) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id, disabled: !enableDrag || readOnly });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  const handleValueChange = useCallback(
    (key: string, value: unknown) => {
      onValueChange?.(key, value);
    },
    [onValueChange]
  );

  const handleActionClick = useCallback(
    (action: { onClick?: (item: Record<string, unknown>, index: number) => void }) => {
      action.onClick?.(item, index);
    },
    [item, index]
  );

  // 계층 구조 들여쓰기
  const indentStyle = level > 0 ? { paddingLeft: `${level * 24}px` } : {};

  return (
    <Div
      ref={setNodeRef}
      style={style}
      className={`flex items-center gap-2 py-2 px-2 border-b border-gray-100 dark:border-gray-700 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors ${isDragging ? 'bg-blue-50 dark:bg-blue-900/20 shadow-lg rounded-lg z-10' : ''} ${className}`}
    >
      {/* 드래그 핸들 */}
      {enableDrag && !readOnly && (
        <Div
          {...attributes}
          {...listeners}
          className="flex-shrink-0 w-6 h-6 flex items-center justify-center cursor-grab active:cursor-grabbing text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-400"
          style={indentStyle}
        >
          <Icon name="grip-vertical" className="w-4 h-4" />
        </Div>
      )}

      {/* 순번 */}
      {showIndex && (
        <Div className="flex-shrink-0 w-8 text-center text-sm text-gray-500 dark:text-gray-400 font-medium">
          {index + 1}
        </Div>
      )}

      {/* 컬럼 데이터 */}
      {columns.map((column) => {
        const cellError = getErrorForColumn(errors, column.key, column.type);
        return (
          <Div
            key={column.key}
            className={`flex-1 min-w-0 ${column.width || ''}`}
            style={column.width ? { flex: 'none', width: column.width } : {}}
          >
            <CellRenderer
              column={column}
              item={item}
              index={index}
              onValueChange={handleValueChange}
              readOnly={readOnly}
              error={cellError}
            />
          </Div>
        );
      })}

      {/* 행 액션 */}
      {rowActions && rowActions.length > 0 && !readOnly && (
        <Div className="flex-shrink-0 flex items-center gap-1">
          {rowActions.map((action) => {
            const isDisabled =
              typeof action.disabled === 'function'
                ? action.disabled(item, index)
                : action.disabled;

            return (
              <Button
                key={action.key}
                type="button"
                onClick={() => handleActionClick(action)}
                disabled={isDisabled}
                className={`p-1.5 rounded transition-colors ${
                  action.danger
                    ? 'text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600'
                    : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300'
                } disabled:opacity-50 disabled:cursor-not-allowed`}
                title={action.label}
              >
                {action.icon ? (
                  <Icon name={action.icon} className="w-4 h-4" />
                ) : (
                  <Span className="text-xs">{action.label}</Span>
                )}
              </Button>
            );
          })}
        </Div>
      )}

      {/* 기본 삭제 버튼 */}
      {canRemove && !rowActions && !readOnly && (
        <Div className="flex-shrink-0">
          <Button
            type="button"
            onClick={onRemove}
            className="p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300 rounded transition-colors"
            title="삭제"
          >
            <Icon name="minus" className="w-4 h-4" />
          </Button>
        </Div>
      )}
    </Div>
  );
};

export default DynamicFieldListItem;
