/**
 * PipeAutocomplete.tsx
 *
 * G7 위지윅 레이아웃 편집기의 파이프 함수 자동완성 컴포넌트
 *
 * 역할:
 * - 파이프 함수 자동완성 제안
 * - 파이프 함수 설명 표시
 * - 파이프 체이닝 지원
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { pipeRegistry } from '../../../PipeRegistry';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('PipeAutocomplete');

// ============================================================================
// 타입 정의
// ============================================================================

export interface PipeAutocompleteProps {
  /** 현재 값 */
  value: string;

  /** 변경 핸들러 */
  onChange: (value: string) => void;

  /** 플레이스홀더 */
  placeholder?: string;

  /** 레이블 */
  label?: string;

  /** 설명 */
  description?: string;

  /** 필수 여부 */
  required?: boolean;

  /** 바인딩 표현식 모드 ({{}} 자동 추가) */
  expressionMode?: boolean;
}

interface PipeSuggestion {
  name: string;
  description: string;
  syntax: string;
  category: string;
}

// ============================================================================
// 파이프 정보 가져오기
// ============================================================================

/**
 * 파이프 카테고리 분류
 */
function getPipeCategory(name: string): string {
  if (['date', 'datetime', 'relativeTime'].includes(name)) return 'date';
  if (['number'].includes(name)) return 'number';
  if (['truncate', 'uppercase', 'lowercase', 'stripHtml'].includes(name)) return 'string';
  if (['default', 'fallback'].includes(name)) return 'default';
  if (['first', 'last', 'join', 'length'].includes(name)) return 'array';
  if (['keys', 'values', 'json'].includes(name)) return 'object';
  if (['localized'].includes(name)) return 'i18n';
  return 'general';
}

/**
 * 파이프 구문 예시
 */
function getPipeSyntax(name: string): string {
  const syntaxMap: Record<string, string> = {
    date: '| date',
    datetime: '| datetime',
    relativeTime: '| relativeTime',
    number: '| number(decimals)',
    truncate: '| truncate(length, suffix)',
    uppercase: '| uppercase',
    lowercase: '| lowercase',
    stripHtml: '| stripHtml',
    default: '| default(value)',
    fallback: '| fallback(value)',
    first: '| first',
    last: '| last',
    join: '| join(separator)',
    length: '| length',
    keys: '| keys',
    values: '| values',
    json: '| json',
    localized: '| localized',
  };
  return syntaxMap[name] || `| ${name}`;
}

function getPipeSuggestions(): PipeSuggestion[] {
  const pipes = pipeRegistry.list();

  return pipes.map((pipe) => ({
    name: pipe.name,
    description: pipe.description || '',
    syntax: getPipeSyntax(pipe.name),
    category: getPipeCategory(pipe.name),
  }));
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function PipeAutocomplete({
  value,
  onChange,
  placeholder = '{{expression}} 또는 {{value | pipe}}',
  label,
  description,
  required = false,
  expressionMode = true,
}: PipeAutocompleteProps) {
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [searchTerm, setSearchTerm] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);
  const suggestionsRef = useRef<HTMLDivElement>(null);

  // 파이프 목록
  const allPipes = useMemo(() => getPipeSuggestions(), []);

  // 파이프 입력 시작 감지
  const isPipeContext = useMemo(() => {
    // 커서 위치에서 | 뒤에 있는지 확인
    return value.includes('|');
  }, [value]);

  // 현재 입력 중인 파이프 이름 추출
  const currentPipeInput = useMemo(() => {
    const lastPipeIndex = value.lastIndexOf('|');
    if (lastPipeIndex === -1) return '';

    // | 뒤의 텍스트 추출
    const afterPipe = value.slice(lastPipeIndex + 1).trim();
    // 괄호 이전까지만 추출
    const parenIndex = afterPipe.indexOf('(');
    if (parenIndex !== -1) return '';

    return afterPipe.replace(/\}\}$/, '').trim();
  }, [value]);

  // 필터링된 파이프 목록
  const filteredPipes = useMemo(() => {
    if (!currentPipeInput && !showSuggestions) return [];

    const search = currentPipeInput.toLowerCase();
    return allPipes.filter(
      (pipe) =>
        pipe.name.toLowerCase().includes(search) ||
        pipe.description.toLowerCase().includes(search)
    );
  }, [allPipes, currentPipeInput, showSuggestions]);

  // 카테고리별 그룹화
  const groupedPipes = useMemo(() => {
    const groups: Record<string, PipeSuggestion[]> = {};
    filteredPipes.forEach((pipe) => {
      if (!groups[pipe.category]) {
        groups[pipe.category] = [];
      }
      groups[pipe.category].push(pipe);
    });
    return groups;
  }, [filteredPipes]);

  // 키보드 이벤트 핸들러
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (!showSuggestions || filteredPipes.length === 0) {
        // | 입력 시 자동완성 표시
        if (e.key === '|' || (e.key === '\\' && e.shiftKey)) {
          setTimeout(() => setShowSuggestions(true), 0);
        }
        return;
      }

      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          setSelectedIndex((prev) => (prev + 1) % filteredPipes.length);
          break;
        case 'ArrowUp':
          e.preventDefault();
          setSelectedIndex((prev) => (prev - 1 + filteredPipes.length) % filteredPipes.length);
          break;
        case 'Enter':
        case 'Tab':
          e.preventDefault();
          if (filteredPipes[selectedIndex]) {
            insertPipe(filteredPipes[selectedIndex]);
          }
          break;
        case 'Escape':
          setShowSuggestions(false);
          break;
      }
    },
    [showSuggestions, filteredPipes, selectedIndex]
  );

  // 파이프 삽입
  const insertPipe = useCallback(
    (pipe: PipeSuggestion) => {
      // 현재 입력 중인 파이프 이름 제거하고 새 파이프 삽입
      const lastPipeIndex = value.lastIndexOf('|');
      let newValue: string;

      if (lastPipeIndex !== -1) {
        // | 이후 부분 교체
        const beforePipe = value.slice(0, lastPipeIndex + 1);
        const hasClosingBraces = value.endsWith('}}');

        // 파이프 이름과 괄호 추가
        const hasParams = pipe.syntax.includes('(');
        const pipeText = hasParams ? ` ${pipe.name}(` : ` ${pipe.name}`;

        newValue = beforePipe + pipeText + (hasClosingBraces ? '}}' : '');
      } else {
        // 새로 파이프 추가
        newValue = `${value} | ${pipe.name}`;
      }

      onChange(newValue);
      setShowSuggestions(false);
      setSelectedIndex(0);

      // 커서를 적절한 위치로 이동
      setTimeout(() => {
        inputRef.current?.focus();
      }, 0);
    },
    [value, onChange]
  );

  // 입력 변경
  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const newValue = e.target.value;
      onChange(newValue);

      // | 입력 시 자동완성 표시
      if (newValue.slice(-1) === '|') {
        setShowSuggestions(true);
        setSelectedIndex(0);
      }
    },
    [onChange]
  );

  // 파이프 버튼 클릭
  const handlePipeButtonClick = useCallback(() => {
    // | 추가하고 자동완성 표시
    const newValue = value.endsWith('}}')
      ? value.slice(0, -2) + ' | }}'
      : value + ' | ';
    onChange(newValue);
    setShowSuggestions(true);
    inputRef.current?.focus();
  }, [value, onChange]);

  // 외부 클릭 시 닫기
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (
        suggestionsRef.current &&
        !suggestionsRef.current.contains(e.target as Node) &&
        !inputRef.current?.contains(e.target as Node)
      ) {
        setShowSuggestions(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // 선택된 항목 스크롤
  useEffect(() => {
    if (showSuggestions && suggestionsRef.current) {
      const selectedItem = suggestionsRef.current.querySelector(`[data-index="${selectedIndex}"]`);
      selectedItem?.scrollIntoView({ block: 'nearest' });
    }
  }, [selectedIndex, showSuggestions]);

  return (
    <div className="relative">
      {label && (
        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
          {label}
          {required && <span className="text-red-500 ml-1">*</span>}
        </label>
      )}

      <div className="flex items-center gap-1">
        {/* 표현식 모드 시 {{ 표시 */}
        {expressionMode && (
          <span className="text-gray-400 dark:text-gray-500 text-sm font-mono">{'{'+'{'}</span>
        )}

        <input
          ref={inputRef}
          type="text"
          value={expressionMode ? value.replace(/^\{\{|\}\}$/g, '') : value}
          onChange={(e) => {
            const val = e.target.value;
            onChange(expressionMode ? `{{${val}}}` : val);
          }}
          onKeyDown={handleKeyDown}
          onFocus={() => {
            if (currentPipeInput) {
              setShowSuggestions(true);
            }
          }}
          placeholder={placeholder}
          className="flex-1 px-2 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
        />

        {/* 표현식 모드 시 }} 표시 */}
        {expressionMode && (
          <span className="text-gray-400 dark:text-gray-500 text-sm font-mono">{'}'+'}'}</span>
        )}

        {/* 파이프 추가 버튼 */}
        <button
          type="button"
          onClick={handlePipeButtonClick}
          className="px-2 py-1.5 text-xs bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded hover:bg-purple-200 dark:hover:bg-purple-900/50"
          title="파이프 함수 추가"
        >
          | pipe
        </button>
      </div>

      {/* 자동완성 드롭다운 */}
      {showSuggestions && filteredPipes.length > 0 && (
        <div
          ref={suggestionsRef}
          className="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-64 overflow-y-auto"
        >
          {Object.entries(groupedPipes).map(([category, pipes]) => (
            <div key={category}>
              <div className="px-3 py-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900 uppercase">
                {category}
              </div>
              {pipes.map((pipe) => {
                const globalIndex = filteredPipes.indexOf(pipe);
                return (
                  <div
                    key={pipe.name}
                    data-index={globalIndex}
                    className={`px-3 py-2 cursor-pointer ${
                      globalIndex === selectedIndex
                        ? 'bg-blue-50 dark:bg-blue-900/30'
                        : 'hover:bg-gray-50 dark:hover:bg-gray-700'
                    }`}
                    onClick={() => insertPipe(pipe)}
                  >
                    <div className="flex items-center justify-between">
                      <span className="font-mono text-sm text-gray-900 dark:text-white">
                        {pipe.name}
                      </span>
                      <span className="text-xs text-gray-400 dark:text-gray-500 font-mono">
                        {pipe.syntax}
                      </span>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                      {pipe.description}
                    </p>
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}

      {/* 파이프 도움말 */}
      {isPipeContext && !showSuggestions && (
        <div className="mt-1 text-xs text-purple-600 dark:text-purple-400">
          파이프 함수를 사용 중입니다. Tab 또는 클릭으로 자동완성하세요.
        </div>
      )}

      {description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {description}
        </p>
      )}
    </div>
  );
}

export default PipeAutocomplete;
