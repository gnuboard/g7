import React from 'react';
import { Div } from '../basic/Div';
import { Label } from '../basic/Label';
import { Select } from '../basic/Select';
import { Checkbox } from '../basic/Checkbox';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface FilterOption {
  id: string | number;
  label: string;
  value: string | number;
}

export interface Filter {
  id: string;
  label: string;
  type: 'select' | 'checkbox';
  options: FilterOption[];
  value?: string | number | string[] | number[];
}

export interface FilterGroupProps {
  title?: string;
  filters: Filter[];
  onChange?: (filterId: string, value: string | number | string[] | number[] | boolean) => void;
  onReset?: () => void;
  showResetButton?: boolean;
  className?: string;
  style?: React.CSSProperties;
}

/**
 * FilterGroup 집합 컴포넌트
 *
 * 다중 필터 조합을 지원하는 필터 그룹 컴포넌트입니다.
 * Select, Checkbox 타입의 필터들을 조합하여 표시합니다.
 *
 * 기본 컴포넌트 조합: Div + Label + Select + Checkbox + Span + Button
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "FilterGroup",
 *   "props": {
 *     "title": "$t:common.filter",
 *     "filters": [
 *       {
 *         "id": "category",
 *         "label": "카테고리",
 *         "type": "select",
 *         "options": [
 *           {"id": 1, "label": "전자기기", "value": "electronics"},
 *           {"id": 2, "label": "의류", "value": "clothing"}
 *         ]
 *       }
 *     ]
 *   }
 * }
 */
export const FilterGroup: React.FC<FilterGroupProps> = ({
  title,
  filters,
  onChange,
  onReset,
  showResetButton = true,
  className = '',
  style,
}) => {
  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedTitle = title ?? t('common.filter');

  const handleSelectChange = (filterId: string, value: string | number) => {
    onChange?.(filterId, value);
  };

  const handleCheckboxChange = (filterId: string, optionValue: string | number, checked: boolean) => {
    const filter = filters.find(f => f.id === filterId);
    if (!filter) return;

    const currentValues = Array.isArray(filter.value) ? filter.value : [];

    // 타입에 따라 분리하여 처리
    if (typeof optionValue === 'string') {
      const stringValues = currentValues.filter((v): v is string => typeof v === 'string');
      const newValues = checked
        ? [...stringValues, optionValue]
        : stringValues.filter(v => v !== optionValue);
      onChange?.(filterId, newValues);
    } else {
      const numberValues = currentValues.filter((v): v is number => typeof v === 'number');
      const newValues = checked
        ? [...numberValues, optionValue]
        : numberValues.filter(v => v !== optionValue);
      onChange?.(filterId, newValues);
    }
  };

  return (
    <Div
      className={`rounded-lg bg-white dark:bg-gray-800 shadow-md border border-gray-200 dark:border-gray-700 p-4 ${className}`}
      style={style}
    >
      {/* 헤더 */}
      <Div className="flex items-center justify-between mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
        <Span className="text-lg font-semibold text-gray-900 dark:text-white">
          {resolvedTitle}
        </Span>
        {showResetButton && onReset && (
          <Button
            onClick={onReset}
            className="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium"
          >
            {t('common.reset')}
          </Button>
        )}
      </Div>

      {/* 필터 목록 */}
      <Div className="space-y-4">
        {filters.map((filter) => (
          <Div key={filter.id} className="space-y-2">
            <Label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
              {filter.label}
            </Label>

            {/* Select 타입 필터 */}
            {filter.type === 'select' && (
              <Select
                value={filter.value as string | number | undefined}
                onChange={(e) => handleSelectChange(filter.id, e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-blue-500 dark:focus:border-blue-400 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              >
                <option value="">{t('common.select_placeholder')}</option>
                {filter.options.map((option) => (
                  <option key={option.id} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}

            {/* Checkbox 타입 필터 */}
            {filter.type === 'checkbox' && (
              <Div className="space-y-2">
                {filter.options.map((option) => {
                  const isChecked = Array.isArray(filter.value)
                    ? filter.value.some(v => v === option.value)
                    : false;

                  return (
                    <Label
                      key={option.id}
                      className="flex items-center gap-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 p-2 rounded-md transition-colors"
                    >
                      <Checkbox
                        checked={isChecked}
                        onChange={(e) =>
                          handleCheckboxChange(filter.id, option.value, e.target.checked)
                        }
                        className="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500"
                      />
                      <Span className="text-sm text-gray-700 dark:text-gray-300">
                        {option.label}
                      </Span>
                    </Label>
                  );
                })}
              </Div>
            )}
          </Div>
        ))}
      </Div>
    </Div>
  );
};
