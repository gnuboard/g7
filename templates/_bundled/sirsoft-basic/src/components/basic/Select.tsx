import React, { useMemo, useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { Div } from './Div';
import { Button } from './Button';
import { Span } from './Span';
import { Svg } from './Svg';
import { Input } from './Input';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:Select')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:Select]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:Select]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:Select]', ...args),
};

export interface SelectOption {
  value: string | number;
  label: string;
  disabled?: boolean;
}

export interface SelectProps extends Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'onChange'> {
  label?: string;
  error?: string;
  options?: SelectOption[] | string[];
  onChange?: (e: React.ChangeEvent<HTMLSelectElement> | { target: { value: string | number } }) => void;
  /** 드롭다운 내 검색 input 활성화 (engine-v1.40.0+) */
  searchable?: boolean;
  /** 검색 input placeholder */
  searchPlaceholder?: string;
}

/**
 * 로케일 코드를 사람이 읽을 수 있는 이름으로 변환
 */
function getLocaleName(locale: string): string {
  const localeNames: Record<string, string> = {
    ko: '한국어',
    en: 'English',
    ja: '日本語',
    zh: '中文',
    es: 'Español',
    fr: 'Français',
    de: 'Deutsch',
  };

  return localeNames[locale] || locale;
}

/**
 * 커스텀 Select 컴포넌트
 *
 * options prop을 사용하여 드롭다운 메뉴를 생성합니다.
 * 둥근 모서리, 그림자, 체크마크가 있는 커스텀 스타일을 지원합니다.
 */
export const Select: React.FC<SelectProps> = ({
  children,
  label,
  error,
  options,
  className = '',
  value,
  onChange,
  disabled,
  searchable = false,
  searchPlaceholder,
  ...props
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [dropdownPos, setDropdownPos] = useState<{ top?: number; bottom?: number; left: number; width: number } | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // options를 SelectOption[] 형식으로 정규화
  const normalizedOptions = useMemo((): SelectOption[] | null => {
    if (!options) return null;

    // 배열이 아닌 경우 (바인딩 에러 또는 잘못된 prop)
    if (!Array.isArray(options)) {
      logger.warn('options prop is not an array:', options);
      return null;
    }

    // 빈 배열인 경우
    if (options.length === 0) return [];

    // string[] 배열인 경우
    if (typeof options[0] === 'string') {
      return (options as string[]).map((locale): SelectOption => ({
        value: locale,
        label: getLocaleName(locale),
      }));
    }

    // SelectOption[] 배열인 경우 (기존 동작)
    return options as SelectOption[];
  }, [options]);

  // 현재 선택된 옵션의 라벨 가져오기
  const selectedLabel = useMemo(() => {
    if (!normalizedOptions) return '';
    const selected = normalizedOptions.find(opt => String(opt.value) === String(value));
    return selected?.label || '';
  }, [normalizedOptions, value]);

  // searchable 모드에서 검색어로 필터링된 옵션
  const visibleOptions = useMemo((): SelectOption[] | null => {
    if (!normalizedOptions) return null;
    if (!searchable || searchTerm.trim() === '') return normalizedOptions;
    const needle = searchTerm.trim().toLowerCase();
    return normalizedOptions.filter(opt =>
      opt.label.toLowerCase().includes(needle) ||
      String(opt.value).toLowerCase().includes(needle)
    );
  }, [normalizedOptions, searchable, searchTerm]);

  // 드롭다운 열릴 때 검색어 초기화 및 input 포커스
  useEffect(() => {
    if (isOpen && searchable) {
      setSearchTerm('');
      // 다음 tick에 포커스 (DOM 렌더 이후)
      const timer = setTimeout(() => searchInputRef.current?.focus(), 0);
      return () => clearTimeout(timer);
    }
    if (!isOpen) {
      setSearchTerm('');
    }
  }, [isOpen, searchable]);

  /**
   * 드롭다운 위치 계산 (버튼 기준 fixed positioning)
   * Modal 등 overflow:hidden 컨테이너 내부에서도 정상 표시
   */
  const updateDropdownPos = useCallback(() => {
    if (!buttonRef.current) return;
    const rect = buttonRef.current.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom;
    const dropdownMaxH = 280; // max-h-60(240px) + search(~40px)
    // 아래 공간 부족 시 위로 열기
    const openUpward = spaceBelow < dropdownMaxH && rect.top > spaceBelow;
    if (openUpward) {
      setDropdownPos({
        bottom: window.innerHeight - rect.top + 4,
        left: rect.left,
        width: rect.width,
      });
    } else {
      setDropdownPos({
        top: rect.bottom + 4,
        left: rect.left,
        width: rect.width,
      });
    }
  }, []);

  // 드롭다운 열릴 때 위치 계산 + 스크롤/리사이즈 추적
  useEffect(() => {
    if (!isOpen) {
      setDropdownPos(null);
      return;
    }
    updateDropdownPos();
    window.addEventListener('scroll', updateDropdownPos, true);
    window.addEventListener('resize', updateDropdownPos);
    return () => {
      window.removeEventListener('scroll', updateDropdownPos, true);
      window.removeEventListener('resize', updateDropdownPos);
    };
  }, [isOpen, updateDropdownPos]);

  // 외부 클릭 시 드롭다운 닫기 (Portal 영역 포함)
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      if (
        containerRef.current && !containerRef.current.contains(target) &&
        (!dropdownRef.current || !dropdownRef.current.contains(target))
      ) {
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

  const handleToggle = () => {
    if (!disabled) {
      setIsOpen(!isOpen);
    }
  };

  const handleSelect = (optionValue: string | number) => {
    if (onChange) {
      // ActionDispatcher가 이벤트를 인식할 수 있도록 preventDefault 메서드 포함
      const syntheticEvent = {
        target: { value: optionValue },
        preventDefault: () => {},
        stopPropagation: () => {},
        type: 'change',
      };
      onChange(syntheticEvent as any);
    }
    setIsOpen(false);
    buttonRef.current?.focus();
  };

  // options가 없으면 기본 select 사용 (children 지원)
  if (!normalizedOptions) {
    return (
      <select
        className={className}
        value={value}
        onChange={onChange as React.ChangeEventHandler<HTMLSelectElement>}
        disabled={disabled}
        {...props}
      >
        {children}
      </select>
    );
  }

  // 기본 스타일 (className이 없을 때 적용)
  const hasCustomStyle = className && className.includes('bg-');
  // 커스텀 스타일에 텍스트 색상이 없으면 기본 텍스트 색상 보충
  const hasTextColor = className && /text-(gray|red|blue|green|yellow|white|black|indigo|purple|pink|orange|amber|emerald|teal|cyan|slate)-\d+|text-white|text-black/.test(className);
  const baseButtonClass = hasCustomStyle
    ? `${className}${hasTextColor ? '' : ' text-gray-700 dark:text-gray-200'}`
    : `w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl text-gray-700 dark:text-gray-200 font-medium focus:ring-2 focus:ring-blue-500 focus:outline-none ${className}`;

  return (
    <Div ref={containerRef} className="relative">
      <Button
        ref={buttonRef}
        type="button"
        onClick={handleToggle}
        disabled={disabled}
        className={`${baseButtonClass} flex items-center justify-between gap-2 text-left cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed`}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
      >
        <Span className="truncate">{selectedLabel || '\u00A0'}</Span>
        <Svg
          className={`w-4 h-4 flex-shrink-0 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </Svg>
      </Button>

      {isOpen && dropdownPos && createPortal(
        <Div
          ref={dropdownRef}
          className="fixed z-[9999] bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-600 overflow-hidden"
          style={{ top: dropdownPos.top, bottom: dropdownPos.bottom, left: dropdownPos.left, width: dropdownPos.width }}
          role="listbox"
        >
          {searchable && (
            <Div className="p-2 border-b border-gray-200 dark:border-gray-600">
              <Input
                ref={searchInputRef}
                type="text"
                value={searchTerm}
                onChange={(e: React.ChangeEvent<HTMLInputElement>) => setSearchTerm(e.target.value)}
                placeholder={searchPlaceholder ?? 'Search...'}
                className="w-full px-3 py-2 bg-gray-100 dark:bg-gray-700 border-0 rounded-lg text-sm text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                role="searchbox"
                aria-label={searchPlaceholder ?? 'Search'}
              />
            </Div>
          )}
          <Div className="py-2 max-h-60 overflow-auto">
            {visibleOptions && visibleOptions.length === 0 && (
              <Div className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 text-center">
                {searchTerm ? 'No results' : ''}
              </Div>
            )}
            {(visibleOptions ?? []).map((option) => {
              const isSelected = String(option.value) === String(value);
              return (
                <Button
                  key={option.value}
                  type="button"
                  onClick={() => !option.disabled && handleSelect(option.value)}
                  disabled={option.disabled}
                  className={`w-full px-4 py-3 text-left flex items-center justify-between hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors ${
                    isSelected ? 'text-blue-600 dark:text-blue-400 font-medium' : 'text-gray-700 dark:text-gray-200'
                  } ${option.disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
                  role="option"
                  aria-selected={isSelected}
                >
                  <Span>{option.label}</Span>
                  {isSelected && (
                    <Svg
                      className="w-5 h-5 text-blue-600 dark:text-blue-400"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </Svg>
                  )}
                </Button>
              );
            })}
          </Div>
        </Div>,
        document.body
      )}
    </Div>
  );
};
