import React, { useState, useEffect } from 'react';
import { Form } from '../basic/Form';
import { Input } from '../basic/Input';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';
import { keyboardActivationProps, FOCUS_VISIBLE_RING, escapeToClose } from '../../support/keyboardActivation';

// G7Core 참조
const G7Core = () => (window as any).G7Core;
const t = (key: string, params?: Record<string, string | number>) =>
  G7Core()?.t?.(key, params) ?? key;

export interface SearchSuggestion {
  id: string | number;
  text: string;
}

export interface SearchBarProps {
  name?: string;
  placeholder?: string;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onSubmit?: (e: React.FormEvent<HTMLFormElement>) => void;
  showButton?: boolean; // true: 검색 버튼 표시, false: 버튼 숨김 (기본값: false)
  suggestions?: SearchSuggestion[];
  onSuggestionClick?: (suggestion: SearchSuggestion) => void;
  showSuggestions?: boolean;
  className?: string; // 외부 className (w-full 등)은 G7Core.style.mergeClasses로 내부 기본 클래스를 오버라이드
  style?: React.CSSProperties;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * SearchBar 집합 컴포넌트
 *
 * 검색 입력 필드와 선택적 버튼을 제공하는 검색 바 컴포넌트입니다.
 * Enter 키를 누르면 항상 검색이 실행됩니다.
 *
 * 기본 컴포넌트 조합: Form + Input + Button + Icon + Div + Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (버튼 없음)
 * {
 *   "name": "SearchBar",
 *   "props": {
 *     "placeholder": "검색어를 입력하세요",
 *     "value": "{{query.search}}",
 *     "showButton": false
 *   }
 * }
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (버튼 있음)
 * {
 *   "name": "SearchBar",
 *   "props": {
 *     "placeholder": "검색어를 입력하세요",
 *     "value": "{{query.search}}",
 *     "showButton": true
 *   }
 * }
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (전체 너비 - className으로 오버라이드)
 * {
 *   "name": "SearchBar",
 *   "props": {
 *     "placeholder": "검색어를 입력하세요",
 *     "value": "{{query.search}}",
 *     "className": "w-full"
 *   }
 * }
 */
export const SearchBar: React.FC<SearchBarProps> = ({
  name = 'search',
  placeholder,
  value: controlledValue,
  onChange,
  onSubmit,
  showButton = false,
  suggestions = [],
  onSuggestionClick,
  showSuggestions = false,
  className = '',
  style,
  id,
  editorAttrs,
}) => {
  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedPlaceholder = placeholder ?? t('common.search_placeholder');

  const [internalValue, setInternalValue] = useState('');
  const [isFocused, setIsFocused] = useState(false);
  // Esc 로 접은 제안 — 다시 입력하거나 입력칸에 다시 들어오면 펼친다
  const [suggestionsDismissed, setSuggestionsDismissed] = useState(false);
  const containerRef = React.useRef<HTMLDivElement>(null);
  const previousValueRef = React.useRef<string>('');

  // blur 후 제안목록을 닫는 지연 타이머. 제안 항목 클릭이 blur 보다 먼저 처리되도록 200ms 미룬다.
  // 그 사이에 컴포넌트가 사라지면(라우팅·조건부 렌더·테스트 teardown) 타이머는 여전히 살아 있어
  // 언마운트된 컴포넌트의 setState 를 호출한다 — 실제로 Vitest 가 teardown 이후 발화한 이 타이머를
  // unhandled error 로 잡아 스위트 종료코드를 1 로 만들었다(테스트 실패는 0건이라 더 눈에 안 띄었다).
  const blurTimerRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(
    () => () => {
      if (blurTimerRef.current !== null) {
        clearTimeout(blurTimerRef.current);
      }
    },
    [],
  );

  const value = controlledValue !== undefined ? controlledValue : internalValue;
  const shouldShowSuggestions = showSuggestions && isFocused && !suggestionsDismissed && suggestions.length > 0 && value.length > 0;

  // 기본 Input 클래스 (w-64 포함)
  const baseInputClasses = 'w-64 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm';
  // 외부 className으로 기본 클래스 오버라이드 (예: w-full이 w-64를 대체)
  const inputClassName = G7Core()?.style?.mergeClasses?.(baseInputClasses, className) ?? `${baseInputClasses} ${className}`;

  // controlledValue가 변경될 때 previousValueRef 동기화
  useEffect(() => {
    if (controlledValue !== undefined) {
      previousValueRef.current = controlledValue;
    }
  }, [controlledValue]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;
    setInternalValue(newValue);
    setSuggestionsDismissed(false);
    onChange?.(e);

    // X 버튼으로 검색어를 지운 경우 감지
    // 이전 값이 있었는데 새 값이 빈 문자열이고, nativeEvent가 없는 경우 = X 버튼 클릭
    if (previousValueRef.current !== '' && newValue === '' && !(e.nativeEvent as any).inputType) {
      // X 버튼 클릭: 자동으로 검색 초기화
      setTimeout(() => {
        const formElement = containerRef.current?.querySelector('form');
        if (formElement) {
          formElement.requestSubmit();
        }
      }, 0);
    }

    previousValueRef.current = newValue;
  };

  // 입력칸과 제안 항목이 포커스를 주고받는 동안에는 목록을 연 채로 둔다 — 종전에는 Tab 으로 제안에 가면 입력칸의 blur 가
  // 200ms 뒤 목록을 닫아 키보드로는 제안을 고를 수 없었다(키보드 접근)
  const keepSuggestionsOpen = () => {
    if (blurTimerRef.current !== null) {
      clearTimeout(blurTimerRef.current);
      blurTimerRef.current = null;
    }
    setIsFocused(true);
    setSuggestionsDismissed(false);
  };
  const closeSuggestionsSoon = () => {
    if (blurTimerRef.current !== null) {
      clearTimeout(blurTimerRef.current);
    }
    blurTimerRef.current = setTimeout(() => {
      blurTimerRef.current = null;
      setIsFocused(false);
    }, 200);
  };

  const handleSuggestionClick = (suggestion: SearchSuggestion) => {
    setInternalValue(suggestion.text);
    const syntheticEvent = {
      target: { value: suggestion.text, name },
    } as React.ChangeEvent<HTMLInputElement>;
    onChange?.(syntheticEvent);
    onSuggestionClick?.(suggestion);
    setIsFocused(false);
  };

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    onSubmit?.(e);
  };

  return (
    // 제안이 펼쳐져 있으면 Esc 로 접는다 — 포커스는 입력칸에 남는다
    <Div ref={containerRef} className="relative" style={style} id={id} {...editorAttrs} onKeyDown={escapeToClose(shouldShowSuggestions, () => setSuggestionsDismissed(true))}>
      <Form onSubmit={handleSubmit} className="relative">
        <Div className={`relative flex items-center ${showButton ? 'gap-2' : ''}`}>
          {/* Input wrapper */}
          <Div className="relative flex-1">
            {/* 검색 아이콘 */}
            <Div className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
              <Icon name={IconName.Search} className="w-5 h-5 text-muted" />
            </Div>

            {/* 검색 입력 */}
            <Input
              type="search"
              name={name}
              value={value}
              onChange={handleChange}
              onFocus={keepSuggestionsOpen}
              onBlur={closeSuggestionsSoon}
              placeholder={resolvedPlaceholder}
              className={inputClassName}
            />
          </Div>

          {/* 검색 버튼 (showButton=true일 때만 표시) */}
          {showButton && (
            <Button
              type="submit"
              className="px-4 py-2 bg-gray-800 dark:bg-gray-600 text-white rounded-lg hover:bg-gray-900 dark:hover:bg-gray-500 transition-colors text-sm font-medium cursor-pointer h-[42px]"
            >
              {t('common.search')}
            </Button>
          )}
        </Div>
      </Form>

      {/* 자동완성 제안 목록 */}
      {shouldShowSuggestions && (
        <Div className="absolute z-10 w-full mt-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-64 overflow-y-auto">
          {suggestions.map((suggestion) => (
            <Div
              key={suggestion.id}
              className={`px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0 transition-colors ${FOCUS_VISIBLE_RING}`}
              onClick={() => handleSuggestionClick(suggestion)}
              {...keyboardActivationProps(() => handleSuggestionClick(suggestion))}
              onFocus={keepSuggestionsOpen}
              onBlur={closeSuggestionsSoon}
            >
              <Div className="flex items-center gap-2">
                <Icon name={IconName.Search} className="w-4 h-4 text-muted" />
                <Span className="text-sm text-gray-700 dark:text-gray-300">{suggestion.text}</Span>
              </Div>
            </Div>
          ))}
        </Div>
      )}
    </Div>
  );
};
