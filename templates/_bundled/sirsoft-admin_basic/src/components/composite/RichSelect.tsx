import React, { useMemo, useState, useRef, useEffect, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Svg } from '../basic/Svg';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// G7Core.renderComponentLayout() 헬퍼 참조
const renderComponentLayout = (
  layoutDefs: any[] | undefined,
  itemContext: Record<string, any>,
  keyPrefix?: string
): React.ReactNode => {
  return (window as any).G7Core?.renderComponentLayout?.(layoutDefs, itemContext, keyPrefix) ?? null;
};

export interface RichSelectOption {
  /** 옵션 고유 값 */
  value: string | number;
  /** 기본 라벨 (selectedTemplate이 없을 때 트리거에 표시) */
  label: string;
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 옵션에 포함된 추가 데이터 (itemTemplate에서 {{item.xxx}}로 접근) */
  [key: string]: unknown;
}

export interface RichSelectProps {
  /** 옵션 목록 */
  options?: RichSelectOption[];
  /** 선택된 값 */
  value?: string | number;
  /** 값 변경 핸들러 */
  onChange?: (e: { target: { value: string | number } }) => void;
  /** 트리거 버튼 placeholder */
  placeholder?: string;
  /** 비활성화 */
  disabled?: boolean;
  /** 추가 클래스 */
  className?: string;
  /** 드롭다운 최대 높이 */
  maxHeight?: string;
  /**
   * 각 옵션 항목 렌더링용 children
   * 템플릿 엔진에서 itemContext로 item, index, isSelected를 주입받음
   */
  children?: React.ReactNode;
  /**
   * 선택된 항목 표시용 children (트리거 버튼에 표시)
   * 없으면 option.label 사용
   */
  selectedChildren?: React.ReactNode;
  /**
   * DynamicRenderer에서 전달되는 component_layout 정의
   * @internal 템플릿 엔진 내부 사용
   */
  __componentLayoutDefs?: {
    /** 각 옵션 항목 렌더링용 레이아웃 정의 */
    item?: any[];
    /** 선택된 항목 표시용 레이아웃 정의 */
    selected?: any[];
  };
}

/**
 * 리치 셀렉트 컴포넌트
 *
 * component_layout을 통해 각 항목을 커스텀 렌더링할 수 있습니다.
 * 파일 선택, 사용자 선택 등 복잡한 정보를 표시해야 하는 드롭다운에 적합합니다.
 *
 * @example JSON 레이아웃에서 사용
 * ```json
 * {
 *   "type": "composite",
 *   "name": "RichSelect",
 *   "props": {
 *     "value": "{{_global.selectedFile}}",
 *     "options": "{{files?.data ?? []}}",
 *     "placeholder": "파일을 선택하세요"
 *   },
 *   "component_layout": {
 *     "item": [
 *       {
 *         "type": "basic",
 *         "name": "Div",
 *         "props": { "className": "flex flex-col" },
 *         "children": [
 *           { "type": "basic", "name": "Span", "text": "{{item.label}}" },
 *           { "type": "basic", "name": "Span", "text": "{{item.size}} • {{item.updated_at}}" }
 *         ]
 *       }
 *     ],
 *     "selected": [
 *       { "type": "basic", "name": "Span", "text": "{{item.label}}" }
 *     ]
 *   },
 *   "actions": [{ "type": "change", "handler": "setState", "params": { "target": "global", "selectedFile": "{{$event.target.value}}" } }]
 * }
 * ```
 */
export const RichSelect: React.FC<RichSelectProps> = ({
  options = [],
  value,
  onChange,
  placeholder,
  disabled = false,
  className = '',
  maxHeight = '320px',
  children,
  selectedChildren,
  __componentLayoutDefs,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  // 선택된 옵션 찾기
  const selectedOption = useMemo(() => {
    if (value === undefined || value === null) return null;
    return options.find(opt => String(opt.value) === String(value)) || null;
  }, [options, value]);

  // 외부 클릭 시 드롭다운 닫기
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
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

  // ESC 키로 드롭다운 닫기
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && isOpen) {
        setIsOpen(false);
        buttonRef.current?.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen]);

  const handleToggle = useCallback(() => {
    if (!disabled) {
      setIsOpen(prev => !prev);
    }
  }, [disabled]);

  const handleSelect = useCallback(
    (optionValue: string | number) => {
      if (onChange) {
        const syntheticEvent = {
          target: { value: optionValue },
          preventDefault: () => {},
          stopPropagation: () => {},
          type: 'change',
        };
        onChange(syntheticEvent as { target: { value: string | number } });
      }
      setIsOpen(false);
      buttonRef.current?.focus();
    },
    [onChange]
  );

  // placeholder 다국어 처리
  const resolvedPlaceholder = placeholder ?? t('common.select');

  // 트리거에 표시할 내용 결정
  const renderTriggerContent = () => {
    if (!selectedOption) {
      return <Span className="text-gray-400 dark:text-gray-500">{resolvedPlaceholder}</Span>;
    }

    // 1. component_layout.selected가 있으면 사용
    if (__componentLayoutDefs?.selected) {
      const rendered = renderComponentLayout(
        __componentLayoutDefs.selected,
        { item: selectedOption, isSelected: true },
        'selected'
      );
      if (rendered) return rendered;
    }

    // 2. selectedChildren이 있으면 사용
    if (selectedChildren) {
      return selectedChildren;
    }

    // 3. 기본: label 표시
    return <Span className="truncate">{selectedOption.label}</Span>;
  };

  // children이 없을 때 기본 항목 렌더링
  const renderDefaultItem = (option: RichSelectOption, isSelected: boolean) => (
    <Div className="flex items-center justify-between w-full">
      <Span
        className={
          isSelected ? 'text-blue-600 dark:text-blue-400 font-medium' : 'text-gray-900 dark:text-white'
        }
      >
        {option.label}
      </Span>
      {isSelected && (
        <Svg className="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
        </Svg>
      )}
    </Div>
  );

  return (
    <Div ref={containerRef} className={`relative ${className}`}>
      {/* 트리거 버튼 */}
      <Button
        ref={buttonRef}
        type="button"
        onClick={handleToggle}
        disabled={disabled}
        className={`
          w-full px-4 py-2.5
          bg-gray-100 dark:bg-gray-700
          border-0 rounded-xl
          text-gray-700 dark:text-gray-200 font-medium
          focus:ring-2 focus:ring-blue-500 focus:outline-none
          flex items-center justify-between gap-2
          text-left cursor-pointer
          disabled:opacity-50 disabled:cursor-not-allowed
        `}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
      >
        <Div className="flex-1 min-w-0">{renderTriggerContent()}</Div>
        <Svg
          className={`w-4 h-4 flex-shrink-0 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </Svg>
      </Button>

      {/* 드롭다운 */}
      {isOpen && (
        <Div
          className="absolute z-50 w-full mt-2 bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-600 overflow-hidden"
          role="listbox"
        >
          <Div className="overflow-auto" style={{ maxHeight }}>
            {options.length === 0 ? (
              <Div className="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                {t('common.no_options')}
              </Div>
            ) : (
              options.map((option, index) => {
                const isSelected = String(option.value) === String(value);
                return (
                  <Button
                    key={option.value}
                    type="button"
                    onClick={() => !option.disabled && handleSelect(option.value)}
                    disabled={option.disabled}
                    className={`
                      w-full px-4 py-3 text-left
                      hover:bg-gray-100 dark:hover:bg-gray-700
                      transition-colors
                      ${isSelected ? 'bg-blue-50 dark:bg-blue-900/20' : ''}
                      ${option.disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
                    `}
                    role="option"
                    aria-selected={isSelected}
                    data-item-value={option.value}
                    data-item-index={index}
                    data-item-selected={isSelected}
                  >
                    {/* 1. component_layout.item이 있으면 사용 */}
                    {__componentLayoutDefs?.item
                      ? renderComponentLayout(
                          __componentLayoutDefs.item,
                          { item: option, index, isSelected },
                          `item-${index}`
                        )
                      : /* 2. children이 있으면 사용 */
                        children
                        ? children
                        : /* 3. 기본 렌더링 */
                          renderDefaultItem(option, isSelected)}
                  </Button>
                );
              })
            )}
          </Div>
        </Div>
      )}
    </Div>
  );
};
