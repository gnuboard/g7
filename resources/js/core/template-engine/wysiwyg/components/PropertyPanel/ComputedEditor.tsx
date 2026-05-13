/**
 * ComputedEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기의 computed 속성 편집 컴포넌트
 *
 * 역할:
 * - 레이아웃 수준 computed 속성 편집
 * - 표현식 및 $switch 객체 지원
 * - $computed.xxx로 참조 가능한 값 정의
 */

import React, { useCallback, useMemo, useState } from 'react';
import { SwitchExpressionEditor, type SwitchExpressionValue } from './SwitchExpressionEditor';
import { PipeAutocomplete } from './PipeAutocomplete';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('ComputedEditor');

// ============================================================================
// 타입 정의
// ============================================================================

/**
 * computed 값 타입 (표현식 문자열 또는 $switch 객체)
 */
export type ComputedValue = string | SwitchExpressionValue;

export interface ComputedEditorProps {
  /** computed 속성 객체 */
  value: Record<string, ComputedValue> | undefined;

  /** 변경 핸들러 */
  onChange: (value: Record<string, ComputedValue> | undefined) => void;

  /** 레이블 */
  label?: string;

  /** 설명 */
  description?: string;
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

function getValueType(value: ComputedValue): 'expression' | 'switch' {
  return isSwitchExpression(value) ? 'switch' : 'expression';
}

// ============================================================================
// 서브 컴포넌트들
// ============================================================================

/**
 * 개별 computed 항목 편집기
 */
function ComputedItem({
  name,
  value,
  onNameChange,
  onValueChange,
  onDelete,
}: {
  name: string;
  value: ComputedValue;
  onNameChange: (newName: string) => void;
  onValueChange: (newValue: ComputedValue) => void;
  onDelete: () => void;
}) {
  const [mode, setMode] = useState<'expression' | 'switch'>(getValueType(value));
  const [isExpanded, setIsExpanded] = useState(true);

  // 모드 전환
  const handleModeChange = useCallback(
    (newMode: 'expression' | 'switch') => {
      setMode(newMode);
      if (newMode === 'switch') {
        // $switch 객체로 변환
        onValueChange({
          $switch: '{{status}}',
          $cases: {},
          $default: typeof value === 'string' ? value : '',
        });
      } else {
        // 표현식으로 변환
        if (isSwitchExpression(value)) {
          onValueChange(value.$default?.toString() || '');
        }
      }
    },
    [value, onValueChange]
  );

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 cursor-pointer"
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
          <code className="px-1.5 py-0.5 text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded">
            $computed.{name}
          </code>
          <span className={`px-1.5 py-0.5 text-xs rounded ${
            mode === 'switch'
              ? 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300'
              : 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'
          }`}>
            {mode === 'switch' ? '$switch' : 'expression'}
          </span>
        </div>

        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onDelete();
          }}
          className="p-1 text-red-500 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded"
          title="computed 삭제"
        >
          <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
            <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
          </svg>
        </button>
      </div>

      {/* 내용 */}
      {isExpanded && (
        <div className="p-3 space-y-3">
          {/* 이름 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              이름
            </label>
            <div className="flex items-center gap-1">
              <span className="text-gray-400 dark:text-gray-500 text-sm">$computed.</span>
              <input
                type="text"
                value={name}
                onChange={(e) => onNameChange(e.target.value)}
                placeholder="propertyName"
                className="flex-1 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
              />
            </div>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              레이아웃에서 <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded">{'{{'}{`$computed.${name || 'name'}`}{'}}'}</code>로 참조
            </p>
          </div>

          {/* 타입 선택 */}
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => handleModeChange('expression')}
              className={`flex-1 px-3 py-1.5 text-xs rounded ${
                mode === 'expression'
                  ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-2 border-blue-500'
                  : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600'
              }`}
            >
              표현식
            </button>
            <button
              type="button"
              onClick={() => handleModeChange('switch')}
              className={`flex-1 px-3 py-1.5 text-xs rounded ${
                mode === 'switch'
                  ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-2 border-blue-500'
                  : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600'
              }`}
            >
              $switch
            </button>
          </div>

          {/* 값 편집 */}
          {mode === 'expression' ? (
            <PipeAutocomplete
              value={typeof value === 'string' ? value : ''}
              onChange={(v) => onValueChange(v)}
              label="표현식"
              description="파이프 함수를 사용할 수 있습니다 (예: | date, | truncate(50))"
            />
          ) : (
            <div className="border-t border-gray-200 dark:border-gray-700 pt-3">
              <SwitchExpressionEditor
                value={isSwitchExpression(value) ? value : undefined}
                onChange={(v) => {
                  if (v) onValueChange(v as SwitchExpressionValue);
                }}
                label="$switch 값"
              />
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ComputedEditor({
  value,
  onChange,
  label = 'Computed 속성',
  description,
}: ComputedEditorProps) {
  const [isExpanded, setIsExpanded] = useState(!!value && Object.keys(value).length > 0);

  // computed 항목 목록
  const computedEntries = useMemo(() => {
    return Object.entries(value || {});
  }, [value]);

  // computed 추가
  const handleAddComputed = useCallback(() => {
    const entries = value || {};
    const newName = `computed_${Object.keys(entries).length + 1}`;
    onChange({
      ...entries,
      [newName]: '',
    });
    setIsExpanded(true);
  }, [value, onChange]);

  // computed 이름 변경
  const handleNameChange = useCallback(
    (oldName: string, newName: string) => {
      if (!value || oldName === newName) return;

      const entries = { ...value };
      const computedValue = entries[oldName];
      delete entries[oldName];
      entries[newName] = computedValue;

      onChange(entries);
    },
    [value, onChange]
  );

  // computed 값 변경
  const handleValueChange = useCallback(
    (name: string, newValue: ComputedValue) => {
      onChange({
        ...value,
        [name]: newValue,
      });
    },
    [value, onChange]
  );

  // computed 삭제
  const handleDelete = useCallback(
    (name: string) => {
      if (!value) return;

      const entries = { ...value };
      delete entries[name];

      onChange(Object.keys(entries).length > 0 ? entries : undefined);
    },
    [value, onChange]
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
          {computedEntries.length > 0 && (
            <span className="px-1.5 py-0.5 text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded">
              {computedEntries.length}개
            </span>
          )}
        </div>

        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            handleAddComputed();
          }}
          className="px-2 py-1 text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded hover:bg-green-200 dark:hover:bg-green-900/50"
        >
          + 추가
        </button>
      </div>

      {/* 내용 */}
      {isExpanded && (
        <div className="p-3 space-y-3">
          {computedEntries.length > 0 ? (
            computedEntries.map(([name, val]) => (
              <ComputedItem
                key={name}
                name={name}
                value={val}
                onNameChange={(newName) => handleNameChange(name, newName)}
                onValueChange={(newValue) => handleValueChange(name, newValue)}
                onDelete={() => handleDelete(name)}
              />
            ))
          ) : (
            <div className="p-4 text-center text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800 rounded">
              <p className="text-sm">computed 속성이 없습니다</p>
              <p className="text-xs mt-1">+ 추가 버튼을 클릭하여 새 computed 속성을 정의하세요</p>
              <p className="text-xs mt-2 text-gray-500 dark:text-gray-400">
                computed는 복잡한 표현식을 재사용 가능한 이름으로 정의합니다.
              </p>
            </div>
          )}

          {/* 사용 예시 */}
          {computedEntries.length > 0 && (
            <div className="p-3 bg-gray-100 dark:bg-gray-900 rounded text-xs">
              <div className="font-medium text-gray-700 dark:text-gray-300 mb-2">
                사용 예시:
              </div>
              <div className="space-y-1 text-gray-600 dark:text-gray-400">
                {computedEntries.slice(0, 3).map(([name]) => (
                  <div key={name} className="font-mono">
                    {'{{'}{`$computed.${name}`}{'}}'}
                  </div>
                ))}
                {computedEntries.length > 3 && (
                  <div className="text-gray-400">...외 {computedEntries.length - 3}개</div>
                )}
              </div>
            </div>
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

export default ComputedEditor;
