// e2e:allow편집기 passthrough 결함#1 수정(커스텀 드롭다운 루트 data-editor-* spread). 라이브 검증은 Chrome MCP T1~T7 매트릭스(에디터 추가/선택/저장 200/reload/게스트 사용자화면)로 수행, 단위 회귀는 Select.test.tsx passthrough describe. 라벨 자동 연결(후속 B7)은 전용 Chrome 접근성 트리 재실측 + LabelAutoLink.test.tsx.
import React, { useMemo, useState, useRef, useEffect, useCallback } from 'react';
import { useAdjacentLabel } from '../../hooks/useAdjacentLabel';
import { createPortal } from 'react-dom';
import { Div } from './Div';
import { Button } from './Button';
import { Span } from './Span';
import { Svg } from './Svg';
import { Input } from './Input';

/** 검색칸 기본 안내 — 언어와 무관하게 「Search...」 였다(7.1.0 J D-J11) */
const selectSearchPlaceholder = (): string => {
  const translated = (window as any).G7Core?.t?.('common.search_placeholder');
  return typeof translated === 'string' && translated !== 'common.search_placeholder' ? translated : 'Search...';
};

/** 검색칸 이름 — 안내 글자와 같은 결함으로 언어와 무관하게 「Search」 로 읽혔다(7.1.0 J D-J11 후속) */
const selectSearchLabel = (): string => {
  const translated = (window as any).G7Core?.t?.('common.search');
  return typeof translated === 'string' && translated !== 'common.search' ? translated : 'Search';
};

/** 검색 결과 없음 문구 — 언어와 무관하게 「No results」 였다(7.1.0 validate R4 동류) */
const selectNoResults = (): string => {
  const translated = (window as any).G7Core?.t?.('common.no_results');
  return typeof translated === 'string' && translated !== 'common.no_results' ? translated : 'No results';
};

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:Select')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:Select]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:Select]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:Select]', ...args),
};

/** 드롭다운 패널 폭 상한(px). 트리거가 이보다 넓으면 트리거 폭이 상한이 된다. */
const DROPDOWN_MAX_WIDTH = 320;
/** 패널 오른쪽 끝과 뷰포트 사이 최소 여유(px) */
const DROPDOWN_VIEWPORT_MARGIN = 8;

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
  /** 값이 비었을 때 버튼에 흐리게 보이는 안내 글자 — 버튼의 읽기 이름도 된다 */
  placeholder?: string;
  /**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용). Select 는 커스텀 드롭다운 루트에
   * 개별 data-editor-* 키를 spread 해야 선택/편집이 닿는다(커스텀 루트
   * passthrough). basic 컴포넌트라 `{...props}` 로 DOM 표준 속성(id 등)도 함께 흐른다.
   */
  editorAttrs?: Record<string, unknown>;
}

/**
 * 로케일 코드를 사람이 읽을 수 있는 이름으로 변환
 */
function getLocaleName(locale: string): string {
  const fromAppConfig = (window as any).G7Core?.state?.get?.('_global.appConfig.localeNames')?.[locale];
  if (fromAppConfig) {
    return fromAppConfig;
  }
  const fallback: Record<string, string> = {
    ko: '한국어',
    en: 'English',
    ja: '日本語',
    zh: '中文',
    es: 'Español',
    fr: 'Français',
    de: 'Deutsch',
  };
  return fallback[locale] || locale;
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
  placeholder,
  editorAttrs,
  'aria-label': ariaLabel,
  ...props
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [dropdownPos, setDropdownPos] = useState<{ top?: number; bottom?: number; left: number; minWidth: number; maxWidth: number } | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const nativeSelectRef = useRef<HTMLSelectElement>(null);

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
   *
   * 패널 폭은 트리거 폭을 **하한**으로 두고 내용(max-content)만큼 넓어진다. 종전에는 트리거 폭에
   * 고정해서, 트리거가 글자폭으로 수축한 경우(호출처 폭 클래스가 빌드 CSS 에 없을 때 등) 옵션
   * 라벨이 두 줄로 꺾여 "상태 / 선택"·"교환결제대 / 기" 처럼 읽혔다. 상한은 max(트리거 폭,
   * DROPDOWN_MAX_WIDTH) 과 뷰포트 오른쪽 여유 중 작은 값 — 긴 라벨(상품명 등)은 종전처럼 상한
   * 안에서 줄바꿈되고, 화면 오른쪽 밖으로 삐져나가지 않는다.
   */
  const updateDropdownPos = useCallback(() => {
    if (!buttonRef.current) return;
    const rect = buttonRef.current.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom;
    const dropdownMaxH = 280; // max-h-60(240px) + search(~40px)
    // 아래 공간 부족 시 위로 열기
    const openUpward = spaceBelow < dropdownMaxH && rect.top > spaceBelow;
    const viewportRoom = window.innerWidth - rect.left - DROPDOWN_VIEWPORT_MARGIN;
    const maxWidth = Math.max(rect.width, Math.min(Math.max(rect.width, DROPDOWN_MAX_WIDTH), viewportRoom));
    if (openUpward) {
      setDropdownPos({
        bottom: window.innerHeight - rect.top + 4,
        left: rect.left,
        minWidth: rect.width,
        maxWidth,
      });
    } else {
      setDropdownPos({
        top: rect.bottom + 4,
        left: rect.left,
        minWidth: rect.width,
        maxWidth,
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

  // 곁 라벨을 이름으로 잇는다 — 목록형 버튼은 「작업 유형 Artisan」 처럼 라벨 뒤에 현재 값이 이어 읽힌다.
  // 종전에는 고른 값(「Artisan」)만 이름이라 무엇을 고르는 칸인지 알 수 없었다(7.1.0 J D-J7). 기본 select 는 라벨만 잇는다.
  // 훅은 아래 기본 select 분기보다 먼저 부른다 — 분기 뒤에 두면 options 유무에 따라 훅 호출 수가 달라진다.
  useAdjacentLabel(
    (normalizedOptions ? buttonRef : nativeSelectRef) as React.RefObject<HTMLElement | null>,
    true,
    !!normalizedOptions
  );

  // options가 없으면 기본 select 사용 (children 지원)
  // .select 시맨틱(admin main.css)을 디폴트로 머지 → 호출처가 className 미지정해도 표준 외형
  // 호출처가 이미 'select' 토큰을 명시한 경우 중복 prepend 방지.
  if (!normalizedOptions) {
    const tokens = (className ?? '').split(/\s+/).filter(Boolean);
    const mergedClassName = tokens.includes('select')
      ? (className ?? '')
      : ['select', className].filter(Boolean).join(' ');
    return (
      <select
        ref={nativeSelectRef}
        className={mergedClassName}
        value={value}
        onChange={onChange as React.ChangeEventHandler<HTMLSelectElement>}
        disabled={disabled}
        aria-label={ariaLabel}
        {...editorAttrs}
        {...props}
      >
        {children}
      </select>
    );
  }

  // 기본 스타일 (className이 없을 때 적용)
  // variant 접두사가 붙은 유틸(disabled:bg-, dark:bg-, hover:bg- 등)은 베이스 외형이 아니므로
  // hasCustomStyle/hasTextColor 판정에서 제외한다. 베이스 토큰만으로 커스텀 외형 여부를 판단해야
  // 호출처가 disabled:bg-gray-100 만 추가했을 때 기본 크기 클래스(w-full px-4 py-2.5)가 유실되지 않는다.
  const baseTokens = (className ?? '')
    .split(/\s+/)
    .filter((t) => t && !t.includes(':')) // variant 접두사(`disabled:`, `dark:`, `hover:` 등) 토큰 제외
    .join(' ');
  const hasCustomStyle = baseTokens.includes('bg-');
  // 커스텀 스타일에 텍스트 색상이 없으면 기본 텍스트 색상 보충
  const hasTextColor = /text-(gray|red|blue|green|yellow|white|black|indigo|purple|pink|orange|amber|emerald|teal|cyan|slate)-\d+|text-white|text-black/.test(baseTokens);
  const baseButtonClass = hasCustomStyle
    ? `${className}${hasTextColor ? '' : ' text-gray-700 dark:text-gray-200'}`
    : `w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl text-sm text-gray-700 dark:text-gray-200 font-medium focus:ring-2 focus:ring-blue-500 focus:outline-none ${className}`;

  // props 는 SelectHTMLAttributes 기반이라 Div(HTMLDivElement) 에 직접 spread 시 타입 불일치.
  // 런타임 동작(편집기 data-editor-* / id 등 DOM 표준 속성 passthrough)은 동일하므로
  // DOM-safe 레코드로 캐스팅해 커스텀 드롭다운 루트에 전달한다.
  const rootDomProps = { ...editorAttrs, ...props } as Record<string, unknown>;

  // 폭은 **바깥 상자**가 쥔다. 커스텀 드롭다운의 버튼은 `w-full` 로 부모를 채우는데,
  // 부모(`relative`)에 폭이 없으면 글자 길이만큼 줄어 호출처가 준 `w-44` 가 무의미해진다
  // (2026-09-16 실측: 「전체」 선택 상자가 80px 로 눌렸다). 호출처의 폭 토큰만 골라
  // 바깥 상자로 올려 준다 — 나머지 외형은 종전대로 버튼이 받는다.
  const widthClass = (className ?? '')
    .split(/\s+/)
    .filter((t) => /^(?:sm:|md:|lg:|xl:)?(?:w-|min-w-|max-w-|flex-1|grow)/.test(t))
    .join(' ');

  return (
    <Div ref={containerRef} className={`relative ${widthClass}`.trim()} {...rootDomProps}>
      <Button
        ref={buttonRef}
        type="button"
        onClick={handleToggle}
        disabled={disabled}
        className={`${baseButtonClass} flex items-center justify-between gap-2 text-left cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed`}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-label={ariaLabel}
      >
        {/* 고른 값이 없으면 placeholder 를 흐린 글자로 보인다 — 종전에는 placeholder 가 바깥 div 속성으로 새고
            버튼은 빈칸이라, 일괄 처리 선택 상자 두 개가 이름도 글자도 없는 빈 상자로 보였다(7.1.0 J D-J11). */}
        <Span className={selectedLabel ? 'truncate' : 'truncate text-gray-400 dark:text-gray-500'}>{selectedLabel || placeholder || '\u00A0'}</Span>
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
          style={{
            top: dropdownPos.top,
            bottom: dropdownPos.bottom,
            left: dropdownPos.left,
            width: 'max-content',
            minWidth: dropdownPos.minWidth,
            maxWidth: dropdownPos.maxWidth,
          }}
          role="listbox"
        >
          {searchable && (
            <Div className="p-2 border-b border-gray-200 dark:border-gray-600">
              <Input
                ref={searchInputRef}
                type="text"
                value={searchTerm}
                onChange={(e: React.ChangeEvent<HTMLInputElement>) => setSearchTerm(e.target.value)}
                placeholder={searchPlaceholder ?? selectSearchPlaceholder()}
                className="w-full px-3 py-2 bg-gray-100 dark:bg-gray-700 border-0 rounded-lg text-sm text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                role="searchbox"
                aria-label={searchPlaceholder ?? selectSearchLabel()}
              />
            </Div>
          )}
          <Div className="py-2 max-h-60 overflow-auto" style={{ scrollbarGutter: 'stable' }}>
            {visibleOptions && visibleOptions.length === 0 && (
              <Div className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 text-center">
                {searchTerm ? selectNoResults() : ''}
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
                  className={`w-full px-4 py-3 text-left text-sm flex items-center justify-between hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors ${
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
