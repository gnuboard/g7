/**
 * GetHelperAutocomplete.tsx
 *
 * G7 위지윅 레이아웃 편집기의 $get 헬퍼 자동완성 컴포넌트
 *
 * 역할:
 * - $get 헬퍼 함수 빌더 UI 제공
 * - 깊은 경로 접근과 폴백 값 설정
 * - 동적 키 경로 지원
 */

import React, { useCallback, useMemo, useState } from 'react';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('GetHelperAutocomplete');

// ============================================================================
// 타입 정의
// ============================================================================

export interface GetHelperAutocompleteProps {
  /** 현재 값 */
  value: string;

  /** 변경 핸들러 */
  onChange: (value: string) => void;

  /** 레이블 */
  label?: string;

  /** 설명 */
  description?: string;

  /** 바인딩 소스 (자동완성용) */
  bindingSources?: {
    dataSources?: string[];
    globalState?: string[];
    localState?: string[];
  };
}

interface GetHelperValue {
  object: string;
  path: string[];
  fallback: string;
}

// ============================================================================
// 헬퍼 함수
// ============================================================================

/**
 * $get 표현식 파싱
 */
function parseGetExpression(value: string): GetHelperValue | null {
  // {{$get(obj, path, fallback)}} 또는 {{$get(obj, [path1, path2], fallback)}} 형식
  const match = value.match(/^\{\{\s*\$get\s*\(\s*(.+?)\s*,\s*(.+?)\s*(?:,\s*(.+?))?\s*\)\s*\}\}$/);

  if (!match) return null;

  const [, object, pathStr, fallback = ''] = match;

  // 경로 파싱
  let path: string[];
  if (pathStr.startsWith('[')) {
    // 배열 형식: [key1, key2]
    try {
      // 간단한 파싱 (완벽하지 않음)
      const pathArray = pathStr
        .slice(1, -1)
        .split(',')
        .map((p) => p.trim().replace(/^['"]|['"]$/g, ''));
      path = pathArray;
    } catch {
      path = [pathStr];
    }
  } else {
    // 문자열 형식
    path = [pathStr.replace(/^['"]|['"]$/g, '')];
  }

  return {
    object: object.trim(),
    path,
    fallback: fallback.trim(),
  };
}

/**
 * $get 표현식 생성
 */
function buildGetExpression(helper: GetHelperValue): string {
  const { object, path, fallback } = helper;

  if (!object) return '';

  // 경로 형식 결정
  let pathStr: string;
  if (path.length === 0) {
    return `{{${object}}}`;
  } else if (path.length === 1) {
    // 단일 경로는 문자열로
    pathStr = path[0].includes('.') || path[0].includes('[')
      ? path[0]
      : `'${path[0]}'`;
  } else {
    // 복수 경로는 배열로
    pathStr = `[${path.map((p) => {
      // 바인딩 표현식이면 그대로, 아니면 문자열로
      if (p.includes('{{') || p.includes('_global') || p.includes('_local') || !p.includes('.')) {
        return p.startsWith("'") || p.startsWith('"') ? p : `'${p}'`;
      }
      return p;
    }).join(', ')}]`;
  }

  if (fallback) {
    return `{{$get(${object}, ${pathStr}, ${fallback})}}`;
  }

  return `{{$get(${object}, ${pathStr})}}`;
}

// ============================================================================
// 서브 컴포넌트들
// ============================================================================

/**
 * 경로 세그먼트 편집기
 */
function PathSegmentEditor({
  segments,
  onChange,
}: {
  segments: string[];
  onChange: (segments: string[]) => void;
}) {
  const handleSegmentChange = useCallback(
    (index: number, value: string) => {
      const newSegments = [...segments];
      newSegments[index] = value;
      onChange(newSegments);
    },
    [segments, onChange]
  );

  const handleAddSegment = useCallback(() => {
    onChange([...segments, '']);
  }, [segments, onChange]);

  const handleRemoveSegment = useCallback(
    (index: number) => {
      const newSegments = segments.filter((_, i) => i !== index);
      onChange(newSegments);
    },
    [segments, onChange]
  );

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
          경로 세그먼트
        </label>
        <button
          type="button"
          onClick={handleAddSegment}
          className="px-2 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-900/50"
        >
          + 추가
        </button>
      </div>

      {segments.map((segment, index) => (
        <div key={index} className="flex items-center gap-2">
          <span className="text-xs text-gray-400 dark:text-gray-500 w-4">
            {index + 1}.
          </span>
          <input
            type="text"
            value={segment}
            onChange={(e) => handleSegmentChange(index, e.target.value)}
            placeholder={index === 0 ? "currency" : `_global.preferredCurrency ?? 'KRW'`}
            className="flex-1 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
          />
          {segments.length > 1 && (
            <button
              type="button"
              onClick={() => handleRemoveSegment(index)}
              className="p-1 text-red-500 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded"
            >
              <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
              </svg>
            </button>
          )}
        </div>
      ))}

      <p className="text-xs text-gray-500 dark:text-gray-400">
        동적 키는 바인딩 없이 직접 입력 (예: _global.currency, currency ?? 'KRW')
      </p>
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function GetHelperAutocomplete({
  value,
  onChange,
  label = '$get 헬퍼',
  description,
  bindingSources,
}: GetHelperAutocompleteProps) {
  const [mode, setMode] = useState<'simple' | 'builder'>(
    value.includes('$get(') ? 'builder' : 'simple'
  );
  const [isExpanded, setIsExpanded] = useState(value.includes('$get('));

  // 파싱된 $get 값
  const parsedValue = useMemo(() => {
    const parsed = parseGetExpression(value);
    return parsed || {
      object: '',
      path: [''],
      fallback: '',
    };
  }, [value]);

  // 모드 전환
  const handleModeChange = useCallback(
    (newMode: 'simple' | 'builder') => {
      setMode(newMode);
      if (newMode === 'builder' && !value.includes('$get(')) {
        // 빌더 모드로 전환 - 기본 구조 생성
        onChange('{{$get(data, path, fallback)}}');
        setIsExpanded(true);
      }
    },
    [value, onChange]
  );

  // 객체 변경
  const handleObjectChange = useCallback(
    (object: string) => {
      const newValue = buildGetExpression({
        ...parsedValue,
        object,
      });
      onChange(newValue || value);
    },
    [parsedValue, value, onChange]
  );

  // 경로 변경
  const handlePathChange = useCallback(
    (path: string[]) => {
      const newValue = buildGetExpression({
        ...parsedValue,
        path,
      });
      onChange(newValue || value);
    },
    [parsedValue, value, onChange]
  );

  // 폴백 변경
  const handleFallbackChange = useCallback(
    (fallback: string) => {
      const newValue = buildGetExpression({
        ...parsedValue,
        fallback,
      });
      onChange(newValue || value);
    },
    [parsedValue, value, onChange]
  );

  // 단순 값 변경
  const handleSimpleChange = useCallback(
    (newValue: string) => {
      onChange(newValue);
    },
    [onChange]
  );

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 cursor-pointer"
        onClick={() => setIsExpanded(!isExpanded)}
      >
        <div className="flex items-center gap-2">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            className={`w-4 h-4 text-gray-500 dark:text-gray-400 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
            viewBox="0 0 20 20"
            fill="currentColor"
          >
            <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
          </svg>
          <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {label}
          </span>
          {mode === 'builder' && (
            <span className="px-1.5 py-0.5 text-xs bg-cyan-100 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300 rounded">
              $get
            </span>
          )}
        </div>
      </div>

      {/* 내용 */}
      {isExpanded && (
        <div className="p-3 space-y-4">
          {/* 모드 선택 */}
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => handleModeChange('simple')}
              className={`flex-1 px-3 py-2 text-sm rounded ${
                mode === 'simple'
                  ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-2 border-blue-500'
                  : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600'
              }`}
            >
              단순 표현식
            </button>
            <button
              type="button"
              onClick={() => handleModeChange('builder')}
              className={`flex-1 px-3 py-2 text-sm rounded ${
                mode === 'builder'
                  ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-2 border-blue-500'
                  : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600'
              }`}
            >
              $get 빌더
            </button>
          </div>

          {mode === 'simple' ? (
            /* 단순 모드 */
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                표현식
              </label>
              <input
                type="text"
                value={value}
                onChange={(e) => handleSimpleChange(e.target.value)}
                placeholder="{{data.path}} 또는 {{$get(obj, path, fallback)}}"
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
              />
            </div>
          ) : (
            /* 빌더 모드 */
            <>
              {/* 대상 객체 */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  대상 객체
                </label>
                <input
                  type="text"
                  value={parsedValue.object}
                  onChange={(e) => handleObjectChange(e.target.value)}
                  placeholder="product.multi_currency_price"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  접근할 객체의 경로 (예: product.prices, _global.settings)
                </p>
              </div>

              {/* 경로 세그먼트 */}
              <PathSegmentEditor
                segments={parsedValue.path}
                onChange={handlePathChange}
              />

              {/* 폴백 값 */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  폴백 값 (선택)
                </label>
                <input
                  type="text"
                  value={parsedValue.fallback}
                  onChange={(e) => handleFallbackChange(e.target.value)}
                  placeholder="product.price_formatted 또는 'N/A'"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  경로가 존재하지 않을 때 반환할 값
                </p>
              </div>

              {/* 결과 미리보기 */}
              <div className="p-3 bg-gray-100 dark:bg-gray-900 rounded">
                <div className="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">
                  생성된 표현식:
                </div>
                <code className="text-sm text-cyan-600 dark:text-cyan-400 font-mono break-all">
                  {buildGetExpression(parsedValue) || '(표현식 입력 필요)'}
                </code>
              </div>

              {/* 사용 예시 */}
              <div className="p-3 bg-cyan-50 dark:bg-cyan-900/20 rounded text-xs">
                <div className="font-medium text-cyan-700 dark:text-cyan-300 mb-2">
                  사용 예시:
                </div>
                <div className="space-y-1 text-cyan-600 dark:text-cyan-400 font-mono">
                  <div>{'{{$get(prices, currency, 0)}}'}</div>
                  <div>{'{{$get(product.prices, [currency, \'formatted\'], \'N/A\')}}'}</div>
                  <div>{'{{$get(_global.settings, [\'mail\', \'host\'], \'localhost\')}}'}</div>
                </div>
              </div>
            </>
          )}
        </div>
      )}

      {/* 설명 */}
      {description && !isExpanded && (
        <p className="px-3 pb-2 text-xs text-gray-500 dark:text-gray-400">
          {description}
        </p>
      )}
    </div>
  );
}

export default GetHelperAutocomplete;
