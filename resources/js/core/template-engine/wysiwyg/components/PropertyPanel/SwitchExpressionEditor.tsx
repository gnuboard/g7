/**
 * SwitchExpressionEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기의 $switch 표현식 편집 컴포넌트
 *
 * 역할:
 * - $switch 구조 시각적 편집 ($switch, $cases, $default)
 * - case 추가/삭제/수정
 * - 중첩 삼항 연산자 대체
 */

import React, { useCallback, useMemo, useState } from 'react';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('SwitchExpressionEditor');

// ============================================================================
// 타입 정의
// ============================================================================

/**
 * $switch 구조 타입
 */
export interface SwitchExpressionValue {
  /** switch 키 표현식 */
  $switch: string;

  /** case별 값 매핑 */
  $cases: Record<string, unknown>;

  /** 기본값 */
  $default?: unknown;
}

export interface SwitchExpressionEditorProps {
  /** $switch 값 */
  value: SwitchExpressionValue | string | undefined;

  /** 변경 핸들러 */
  onChange: (value: SwitchExpressionValue | string | undefined) => void;

  /** 레이블 */
  label?: string;

  /** 설명 */
  description?: string;

  /** case 값 타입 힌트 */
  valueType?: 'string' | 'expression' | 'any';
}

// ============================================================================
// 헬퍼 함수
// ============================================================================

function isSwitchExpression(value: unknown): value is SwitchExpressionValue {
  return (
    typeof value === 'object' &&
    value !== null &&
    '$switch' in value &&
    '$cases' in value
  );
}

// ============================================================================
// 서브 컴포넌트들
// ============================================================================

/**
 * Case 항목 편집기
 */
function CaseItem({
  caseKey,
  caseValue,
  valueType,
  onKeyChange,
  onValueChange,
  onDelete,
}: {
  caseKey: string;
  caseValue: unknown;
  valueType: 'string' | 'expression' | 'any';
  onKeyChange: (newKey: string) => void;
  onValueChange: (newValue: unknown) => void;
  onDelete: () => void;
}) {
  const stringValue = typeof caseValue === 'string' ? caseValue : JSON.stringify(caseValue);

  return (
    <div className="flex items-start gap-2 p-2 bg-gray-50 dark:bg-gray-800 rounded">
      <div className="flex-1">
        <label className="block text-xs text-gray-500 dark:text-gray-400 mb-1">
          Case 키
        </label>
        <input
          type="text"
          value={caseKey}
          onChange={(e) => onKeyChange(e.target.value)}
          placeholder="active, pending, error..."
          className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
        />
      </div>
      <div className="flex-[2]">
        <label className="block text-xs text-gray-500 dark:text-gray-400 mb-1">
          반환 값
        </label>
        <input
          type="text"
          value={stringValue}
          onChange={(e) => {
            const val = e.target.value;
            // JSON 파싱 시도
            if (valueType === 'any') {
              try {
                onValueChange(JSON.parse(val));
              } catch {
                onValueChange(val);
              }
            } else {
              onValueChange(val);
            }
          }}
          placeholder={valueType === 'expression' ? '{{expression}}' : 'value'}
          className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
        />
      </div>
      <button
        type="button"
        onClick={onDelete}
        className="mt-5 p-1 text-red-500 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded"
        title="case 삭제"
      >
        <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
          <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
        </svg>
      </button>
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function SwitchExpressionEditor({
  value,
  onChange,
  label = '$switch 표현식',
  description,
  valueType = 'string',
}: SwitchExpressionEditorProps) {
  const [isExpanded, setIsExpanded] = useState(isSwitchExpression(value));
  const [mode, setMode] = useState<'switch' | 'simple'>(
    isSwitchExpression(value) ? 'switch' : 'simple'
  );

  // $switch 값으로 변환
  const switchValue = useMemo<SwitchExpressionValue | null>(() => {
    if (isSwitchExpression(value)) {
      return value;
    }
    return null;
  }, [value]);

  // 모드 전환
  const handleModeChange = useCallback(
    (newMode: 'switch' | 'simple') => {
      setMode(newMode);
      if (newMode === 'switch' && !switchValue) {
        // $switch 구조로 변환
        onChange({
          $switch: '{{status}}',
          $cases: {},
          $default: typeof value === 'string' ? value : '',
        });
        setIsExpanded(true);
      } else if (newMode === 'simple') {
        // 단순 값으로 변환
        onChange(switchValue?.$default?.toString() || '');
      }
    },
    [value, switchValue, onChange]
  );

  // $switch 키 변경
  const handleSwitchKeyChange = useCallback(
    (newKey: string) => {
      if (!switchValue) return;
      onChange({
        ...switchValue,
        $switch: newKey,
      });
    },
    [switchValue, onChange]
  );

  // case 추가
  const handleAddCase = useCallback(() => {
    if (!switchValue) return;
    const cases = switchValue.$cases || {};
    const newKey = `case_${Object.keys(cases).length + 1}`;
    onChange({
      ...switchValue,
      $cases: {
        ...cases,
        [newKey]: '',
      },
    });
  }, [switchValue, onChange]);

  // case 키 변경
  const handleCaseKeyChange = useCallback(
    (oldKey: string, newKey: string) => {
      if (!switchValue || oldKey === newKey) return;

      const cases = { ...switchValue.$cases };
      const caseValue = cases[oldKey];
      delete cases[oldKey];
      cases[newKey] = caseValue;

      onChange({
        ...switchValue,
        $cases: cases,
      });
    },
    [switchValue, onChange]
  );

  // case 값 변경
  const handleCaseValueChange = useCallback(
    (caseKey: string, newValue: unknown) => {
      if (!switchValue) return;
      onChange({
        ...switchValue,
        $cases: {
          ...switchValue.$cases,
          [caseKey]: newValue,
        },
      });
    },
    [switchValue, onChange]
  );

  // case 삭제
  const handleCaseDelete = useCallback(
    (caseKey: string) => {
      if (!switchValue) return;

      const cases = { ...switchValue.$cases };
      delete cases[caseKey];

      onChange({
        ...switchValue,
        $cases: cases,
      });
    },
    [switchValue, onChange]
  );

  // $default 변경
  const handleDefaultChange = useCallback(
    (newDefault: string) => {
      if (!switchValue) return;

      let parsedValue: unknown = newDefault;
      if (valueType === 'any') {
        try {
          parsedValue = JSON.parse(newDefault);
        } catch {
          parsedValue = newDefault;
        }
      }

      onChange({
        ...switchValue,
        $default: parsedValue || undefined,
      });
    },
    [switchValue, valueType, onChange]
  );

  // 단순 값 변경
  const handleSimpleValueChange = useCallback(
    (newValue: string) => {
      onChange(newValue || undefined);
    },
    [onChange]
  );

  // cases 목록
  const caseEntries = useMemo(() => {
    return Object.entries(switchValue?.$cases || {});
  }, [switchValue?.$cases]);

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
          {mode === 'switch' && (
            <span className="px-1.5 py-0.5 text-xs bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300 rounded">
              $switch
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
              단순 값
            </button>
            <button
              type="button"
              onClick={() => handleModeChange('switch')}
              className={`flex-1 px-3 py-2 text-sm rounded ${
                mode === 'switch'
                  ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-2 border-blue-500'
                  : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600'
              }`}
            >
              $switch 표현식
            </button>
          </div>

          {mode === 'simple' ? (
            /* 단순 값 모드 */
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                값
              </label>
              <input
                type="text"
                value={typeof value === 'string' ? value : ''}
                onChange={(e) => handleSimpleValueChange(e.target.value)}
                placeholder="값 또는 {{expression}}"
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
              />
            </div>
          ) : switchValue ? (
            /* $switch 모드 */
            <>
              {/* $switch 키 */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Switch 키 ($switch)
                </label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm">
                    {'{{'}
                  </span>
                  <input
                    type="text"
                    value={(switchValue.$switch || '').replace(/^\{\{|\}\}$/g, '')}
                    onChange={(e) => handleSwitchKeyChange(`{{${e.target.value}}}`)}
                    placeholder="status"
                    className="w-full pl-8 pr-8 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
                  />
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm">
                    {'}}'}
                  </span>
                </div>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  분기할 값을 결정하는 표현식
                </p>
              </div>

              {/* $cases */}
              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
                    Cases ($cases) - {caseEntries.length}개
                  </label>
                  <button
                    type="button"
                    onClick={handleAddCase}
                    className="px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-900/50"
                  >
                    + 추가
                  </button>
                </div>

                {caseEntries.length > 0 ? (
                  <div className="space-y-2">
                    {caseEntries.map(([key, val]) => (
                      <CaseItem
                        key={key}
                        caseKey={key}
                        caseValue={val}
                        valueType={valueType}
                        onKeyChange={(newKey) => handleCaseKeyChange(key, newKey)}
                        onValueChange={(newValue) => handleCaseValueChange(key, newValue)}
                        onDelete={() => handleCaseDelete(key)}
                      />
                    ))}
                  </div>
                ) : (
                  <div className="p-4 text-center text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800 rounded">
                    <p className="text-sm">case가 없습니다</p>
                    <p className="text-xs mt-1">+ 추가 버튼을 클릭하여 case를 추가하세요</p>
                  </div>
                )}
              </div>

              {/* $default */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  기본값 ($default)
                </label>
                <input
                  type="text"
                  value={
                    typeof switchValue.$default === 'string'
                      ? switchValue.$default
                      : JSON.stringify(switchValue.$default ?? '')
                  }
                  onChange={(e) => handleDefaultChange(e.target.value)}
                  placeholder="기본 반환 값"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  매칭되는 case가 없을 때 반환되는 값
                </p>
              </div>

              {/* JSON 미리보기 */}
              <div className="p-3 bg-gray-100 dark:bg-gray-900 rounded text-xs">
                <div className="font-medium text-gray-700 dark:text-gray-300 mb-2">
                  생성될 JSON 미리보기:
                </div>
                <pre className="text-gray-600 dark:text-gray-400 overflow-x-auto">
                  {JSON.stringify(switchValue, null, 2)}
                </pre>
              </div>
            </>
          ) : null}
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

export default SwitchExpressionEditor;
