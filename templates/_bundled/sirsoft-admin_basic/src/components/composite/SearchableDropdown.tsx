import React, { useMemo, useState, useRef, useEffect, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Svg } from '../basic/Svg';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조 — 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다.
// 레이아웃이 문구를 넘기지 않으면 기본 문구가 언어와 무관하게 영어였다(7.1.0 O-C3b-4).
const tOr = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = (window as any).G7Core?.t?.(key, params);
  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

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
  /**
   * 다중 선택에서 둘 이상 골랐을 때의 트리거 문구. `{count}` 가 고른 수로 치환된다.
   *
   * 기본값이 영어인 것은 이 컴포넌트가 코어 번역을 읽지 않기 때문이다 — 화면(레이아웃)이
   * `$t:` 로 넘긴다. 넘기지 않으면 한국어 관리자 화면에 「2 selected」 가 그대로 뜬다
   * (2026-09-21 브라우저 실측: 회원 배송지 목록의 회원 필터).
   */
  selectedCountLabel?: string;
  /** 트리거 버튼 아이콘 */
  triggerIcon?: React.ReactNode;
  /** 비활성화 */
  disabled?: boolean;
  /** 추가 클래스 */
  className?: string;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
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
  searchPlaceholder: searchPlaceholderProp,
  onSearch,
  noResultsText: noResultsTextProp,
  initialText: initialTextProp,
  triggerLabel: triggerLabelProp,
  selectedCountLabel: selectedCountLabelProp,
  triggerIcon,
  disabled = false,
  className = '',
  id,
  editorAttrs,
}) => {
  // 기본 문구는 화면 언어를 따른다 — 레이아웃이 넘긴 문구가 우선이다(7.1.0 O-C3b-4)
  const searchPlaceholder = searchPlaceholderProp ?? tOr('common.search_placeholder', 'Search...');
  const noResultsText = noResultsTextProp ?? tOr('common.no_results', 'No results found');
  const initialText = initialTextProp ?? tOr('common.type_to_search', 'Type to search');
  const triggerLabel = triggerLabelProp ?? tOr('common.select_placeholder', 'Select');
  const selectedCountLabel = selectedCountLabelProp ?? tOr('common.selected_count', '{count} selected', { count: '{count}' });
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
  //
  // 라벨·설명을 **문자열로 좁혀서** 비교한다. 옵션은 화면마다 다른 곳에서 온다 — 검색 API,
  // 현재 선택값 시드, 확장이 주입한 목록. 그중 하나가 문자열이 아닌 값을 흘리면 종전에는
  // `opt.label.toLowerCase is not a function` 이 나면서 **드롭다운 전체가 언마운트**됐다
  // (실측 §9 T-D2: 알림 템플릿 수정 창의 역할 칸이 검색어 첫 글자에 사라졌다).
  // 한 항목이 이상한 것과 그 화면을 못 쓰게 되는 것은 대가가 다르다.
  const filteredOptions = useMemo(() => {
    if (!searchTerm.trim()) return options;
    const term = searchTerm.toLowerCase();
    return options.filter(
      opt =>
        String(opt.label ?? '').toLowerCase().includes(term) ||
        String(opt.description ?? '').toLowerCase().includes(term)
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
      return selectedCountLabel.replace('{count}', String(count));
    }
    const selected = options.find(opt => String(opt.value) === String(selectedValues[0]));
    return selected?.label || triggerLabel;
  }, [selectedValues, options, multiple, triggerLabel, selectedCountLabel]);

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
    if (!isOpen) {
      // 닫힐 때 검색어 초기화
      setSearchTerm('');
      return;
    }

    // 약간의 딜레이 후 포커스 (렌더링 완료 후)
    // unmount 또는 isOpen 변경 시 타이머 해제 — 그렇지 않으면 jsdom teardown
    // 후에 document 참조로 ReferenceError 발생 (테스트 환경) 및 빠른 모달
    // toggle 시 race condition 가능 (프로덕션).
    const timerId = setTimeout(() => {
      const input = document.getElementById(searchInputId) as HTMLInputElement;
      input?.focus();
    }, 50);

    return () => {
      clearTimeout(timerId);
    };
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
    <Div ref={containerRef} className={`relative inline-block ${className}`} id={id} {...editorAttrs}>
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
