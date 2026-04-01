import React, { useMemo, useState, useRef, useEffect, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Svg } from '../basic/Svg';

export interface SearchableDropdownOption {
  value: string | number;
  label: string;
  description?: string;
  disabled?: boolean;
}

export interface SearchableDropdownProps {
  /** 옵션 목록 */
  options?: SearchableDropdownOption[];
  /** 선택된 값 (단일: string|number, 다중: 배열) */
  value?: string | number | (string | number)[];
  /** 값 변경 핸들러 */
  onChange?: (value: string | number | (string | number)[]) => void;
  /** 다중 선택 모드 활성화 */
  multiple?: boolean;
  /** 검색창 placeholder */
  searchPlaceholder?: string;
  /** 외부 검색 핸들러 (백엔드 연동용) */
  onSearch?: (searchTerm: string) => void;
  /** 결과 없음 메시지 */
  noResultsText?: string;
  /** 초기 안내 문구 */
  initialText?: string;
  /** 트리거 버튼 텍스트 */
  triggerLabel?: string;
  /** 트리거 버튼 아이콘 */
  triggerIcon?: React.ReactNode;
  /** 비활성화 */
  disabled?: boolean;
  /** 추가 클래스 */
  className?: string;
}

/**
 * 검색 가능한 드롭다운 컴포넌트
 *
 * 검색어 입력으로 옵션을 필터링하고 선택할 수 있습니다.
 * 단일 선택 및 다중 선택 모드를 지원합니다.
 */
export const SearchableDropdown: React.FC<SearchableDropdownProps> = ({
  options = [],
  value,
  onChange,
  multiple = false,
  searchPlaceholder = 'Search...',
  onSearch,
  noResultsText = 'No results found',
  initialText = 'Type to search',
  triggerLabel = 'Select',
  triggerIcon,
  disabled = false,
  className = '',
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const searchInputId = useRef(`searchable-dropdown-input-${Math.random().toString(36).slice(2, 9)}`).current;

  // 선택된 값들을 배열로 정규화
  const selectedValues = useMemo((): (string | number)[] => {
    if (value === undefined || value === null) return [];
    if (Array.isArray(value)) return value;
    return [value];
  }, [value]);

  // 검색어로 옵션 필터링
  const filteredOptions = useMemo(() => {
    if (!searchTerm.trim()) return options;
    const term = searchTerm.toLowerCase();
    return options.filter(
      opt =>
        opt.label.toLowerCase().includes(term) ||
        opt.description?.toLowerCase().includes(term)
    );
  }, [options, searchTerm]);

  // 선택된 항목의 라벨 표시
  const displayLabel = useMemo(() => {
    if (selectedValues.length === 0) return triggerLabel;
    if (multiple) {
      const count = selectedValues.length;
      if (count === 1) {
        const selected = options.find(opt => String(opt.value) === String(selectedValues[0]));
        return selected?.label || triggerLabel;
      }
      return `${count} selected`;
    }
    const selected = options.find(opt => String(opt.value) === String(selectedValues[0]));
    return selected?.label || triggerLabel;
  }, [selectedValues, options, multiple, triggerLabel]);

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

  // 드롭다운 열릴 때 검색 입력에 포커스
  useEffect(() => {
    if (isOpen) {
      // 약간의 딜레이 후 포커스 (렌더링 완료 후)
      setTimeout(() => {
        const input = document.getElementById(searchInputId) as HTMLInputElement;
        input?.focus();
      }, 50);
    } else {
      // 닫힐 때 검색어 초기화
      setSearchTerm('');
    }
  }, [isOpen, searchInputId]);

  const handleToggle = useCallback(() => {
    if (!disabled) {
      setIsOpen(prev => !prev);
    }
  }, [disabled]);

  const handleSearchChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const term = e.target.value;
      setSearchTerm(term);
      onSearch?.(term);
    },
    [onSearch]
  );

  const handleSelect = useCallback(
    (optionValue: string | number) => {
      if (multiple) {
        // 다중 선택: 토글
        const isSelected = selectedValues.some(v => String(v) === String(optionValue));
        const newValues = isSelected
          ? selectedValues.filter(v => String(v) !== String(optionValue))
          : [...selectedValues, optionValue];
        onChange?.(newValues);
      } else {
        // 단일 선택: 선택 후 닫기
        onChange?.(optionValue);
        setIsOpen(false);
        buttonRef.current?.focus();
      }
    },
    [multiple, selectedValues, onChange]
  );

  const isSelected = (optionValue: string | number): boolean => {
    return selectedValues.some(v => String(v) === String(optionValue));
  };

  // 결과 상태 결정
  const showInitialMessage = searchTerm.length === 0 && options.length === 0;
  const showNoResults = searchTerm.length > 0 && filteredOptions.length === 0;
  const showOptions = filteredOptions.length > 0;

  return (
    <Div ref={containerRef} className={`relative inline-block ${className}`}>
      {/* 트리거 버튼 */}
      <Button
        ref={buttonRef}
        type="button"
        onClick={handleToggle}
        disabled={disabled}
        className={`
          px-4 py-2.5 bg-gray-100 dark:bg-gray-700
          border-0 rounded-xl
          text-gray-700 dark:text-gray-200 font-medium
          focus:ring-2 focus:ring-blue-500 focus:outline-none
          flex items-center justify-between gap-2
          text-left cursor-pointer
          disabled:opacity-50 disabled:cursor-not-allowed
          min-w-[200px]
        `}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
      >
        <Div className="flex items-center gap-2">
          {triggerIcon}
          <Span className="truncate">{displayLabel}</Span>
        </Div>
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
          className="absolute z-50 w-full mt-2 bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-600 overflow-hidden min-w-[280px]"
          role="listbox"
        >
          {/* 검색 입력 */}
          <Div className="p-3 border-b border-gray-200 dark:border-gray-700">
            <input
              id={searchInputId}
              type="text"
              value={searchTerm}
              onChange={handleSearchChange}
              placeholder={searchPlaceholder}
              className="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent focus:outline-none"
            />
          </Div>

          {/* 옵션 목록 */}
          <Div className="max-h-80 overflow-auto">
            {/* 초기 안내 메시지 */}
            {showInitialMessage && (
              <Div className="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                {initialText}
              </Div>
            )}

            {/* 결과 없음 메시지 */}
            {showNoResults && (
              <Div className="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                {noResultsText}
              </Div>
            )}

            {/* 옵션 목록 */}
            {showOptions && (
              <Div className="py-2">
                {filteredOptions.map(option => {
                  const selected = isSelected(option.value);
                  return (
                    <Button
                      key={option.value}
                      type="button"
                      onClick={() => !option.disabled && handleSelect(option.value)}
                      disabled={option.disabled}
                      className={`
                        w-full px-4 py-3 text-left
                        flex items-start gap-3
                        hover:bg-gray-100 dark:hover:bg-gray-700
                        transition-colors
                        ${option.disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
                      `}
                      role="option"
                      aria-selected={selected}
                    >
                      {/* 체크 아이콘 영역 */}
                      <Div className="w-5 h-5 flex-shrink-0 mt-0.5">
                        {selected && (
                          <Svg
                            className="w-5 h-5 text-blue-600 dark:text-blue-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                          >
                            <path
                              strokeLinecap="round"
                              strokeLinejoin="round"
                              strokeWidth={2}
                              d="M5 13l4 4L19 7"
                            />
                          </Svg>
                        )}
                      </Div>

                      {/* 옵션 내용 */}
                      <Div className="flex-1 min-w-0">
                        <Div
                          className={`font-medium ${
                            selected
                              ? 'text-blue-600 dark:text-blue-400'
                              : 'text-gray-900 dark:text-white'
                          }`}
                        >
                          {option.label}
                        </Div>
                        {option.description && (
                          <Div className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            {option.description}
                          </Div>
                        )}
                      </Div>
                    </Button>
                  );
                })}
              </Div>
            )}
          </Div>
        </Div>
      )}
    </Div>
  );
};
