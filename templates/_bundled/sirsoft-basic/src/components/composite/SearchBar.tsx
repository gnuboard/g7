import React, { useState, useEffect } from 'react';
import { Form } from '../basic/Form';
import { Input } from '../basic/Input';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

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
  className?: string;
  style?: React.CSSProperties;
    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
/**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
   */
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
  const containerRef = React.useRef<HTMLDivElement>(null);
  const previousValueRef = React.useRef<string>('');

  const value = controlledValue !== undefined ? controlledValue : internalValue;
  const shouldShowSuggestions = showSuggestions && isFocused && suggestions.length > 0 && value.length > 0;

  // controlledValue가 변경될 때 previousValueRef 동기화
  useEffect(() => {
    if (controlledValue !== undefined) {
      previousValueRef.current = controlledValue;
    }
  }, [controlledValue]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;
    setInternalValue(newValue);
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
    <Div ref={containerRef} className={`relative ${className}`} style={style} id={id} {...editorAttrs}>
      <Form onSubmit={handleSubmit} className="relative">
        <Div className={`relative flex items-center ${showButton ? 'gap-2' : ''}`}>
          {/* Input wrapper */}
          <Div className="relative flex-1">
            {/* 검색 아이콘 */}
            <Div className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
              <Icon name={IconName.Search} className="w-5 h-5 text-gray-400 dark:text-gray-500" />
            </Div>

            {/* 검색 입력 */}
            <Input
              type="search"
              name={name}
              value={value}
              onChange={handleChange}
              onFocus={() => setIsFocused(true)}
              onBlur={() => setTimeout(() => setIsFocused(false), 200)}
              placeholder={resolvedPlaceholder}
              className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm"
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
              className="px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0 transition-colors"
              onClick={() => handleSuggestionClick(suggestion)}
            >
              <Div className="flex items-center gap-2">
                <Icon name={IconName.Search} className="w-4 h-4 text-gray-400 dark:text-gray-500" />
                <Span className="text-sm text-gray-700 dark:text-gray-300">{suggestion.text}</Span>
              </Div>
            </Div>
          ))}
        </Div>
      )}
    </Div>
  );
};
