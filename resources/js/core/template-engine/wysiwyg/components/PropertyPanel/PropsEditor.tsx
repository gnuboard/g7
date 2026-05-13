/**
 * PropsEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기의 Props 편집 컴포넌트
 *
 * 역할:
 * - 컴포넌트 메타데이터 기반 동적 폼 생성
 * - 다양한 prop 타입 지원 (text, number, boolean, select, color, className, expression)
 * - 바인딩 표현식 지원
 */

import React, { useCallback, useMemo, useState } from 'react';
import { useComponentMetadata } from '../../hooks/useComponentMetadata';
import type { ComponentDefinition, PropSchema } from '../../types/editor';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('PropsEditor');

// ============================================================================
// 타입 정의
// ============================================================================

export interface PropsEditorProps {
  /** 편집할 컴포넌트 */
  component: ComponentDefinition;

  /** 변경 핸들러 */
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

interface FieldProps {
  schema: PropSchema;
  value: unknown;
  onChange: (value: unknown) => void;
}

// ============================================================================
// 필드 컴포넌트들
// ============================================================================

/**
 * 텍스트 입력 필드
 */
function TextField({ schema, value, onChange }: FieldProps) {
  const [showBinding, setShowBinding] = useState(
    typeof value === 'string' && value.includes('{{')
  );

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
      onChange(e.target.value);
    },
    [onChange]
  );

  const isMultiline = schema.type === 'textarea';
  const stringValue = typeof value === 'string' ? value : '';

  return (
    <div>
      <div className="flex items-center justify-between mb-1">
        <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
          {schema.label || schema.name}
          {schema.required && <span className="text-red-500 ml-1">*</span>}
        </label>
        <button
          type="button"
          className={`text-xs px-1.5 py-0.5 rounded ${
            showBinding
              ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
              : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'
          }`}
          onClick={() => setShowBinding(!showBinding)}
          title="바인딩 표현식 사용"
        >
          {'{{}}'}
        </button>
      </div>

      {isMultiline ? (
        <textarea
          value={stringValue}
          onChange={handleChange}
          placeholder={schema.placeholder || `${schema.name} 입력`}
          rows={3}
          className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 resize-none"
        />
      ) : (
        <input
          type="text"
          value={stringValue}
          onChange={handleChange}
          placeholder={schema.placeholder || `${schema.name} 입력`}
          className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
        />
      )}

      {schema.description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}

      {showBinding && (
        <p className="mt-1 text-xs text-blue-600 dark:text-blue-400">
          바인딩 예시: {'{{users.data[0].name}}'}
        </p>
      )}
    </div>
  );
}

/**
 * 숫자 입력 필드
 */
function NumberField({ schema, value, onChange }: FieldProps) {
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const numValue = parseFloat(e.target.value);
      onChange(isNaN(numValue) ? undefined : numValue);
    },
    [onChange]
  );

  const numValue = typeof value === 'number' ? value : '';

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        {schema.label || schema.name}
        {schema.required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <input
        type="number"
        value={numValue}
        onChange={handleChange}
        placeholder={schema.placeholder || '0'}
        min={schema.min}
        max={schema.max}
        step={schema.step || 1}
        className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
      />
      {schema.description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}
    </div>
  );
}

/**
 * 불리언 체크박스 필드
 */
function BooleanField({ schema, value, onChange }: FieldProps) {
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      onChange(e.target.checked);
    },
    [onChange]
  );

  const boolValue = Boolean(value);

  return (
    <div className="flex items-start gap-2">
      <input
        type="checkbox"
        id={`prop-${schema.name}`}
        checked={boolValue}
        onChange={handleChange}
        className="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500"
      />
      <div>
        <label
          htmlFor={`prop-${schema.name}`}
          className="text-sm font-medium text-gray-700 dark:text-gray-300"
        >
          {schema.label || schema.name}
        </label>
        {schema.description && (
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {schema.description}
          </p>
        )}
      </div>
    </div>
  );
}

/**
 * 선택 드롭다운 필드
 */
function SelectField({ schema, value, onChange }: FieldProps) {
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLSelectElement>) => {
      onChange(e.target.value || undefined);
    },
    [onChange]
  );

  const stringValue = typeof value === 'string' ? value : '';
  const options = schema.options || [];

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        {schema.label || schema.name}
        {schema.required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <select
        value={stringValue}
        onChange={handleChange}
        className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
      >
        <option value="">선택하세요</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {schema.description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}
    </div>
  );
}

/**
 * 색상 선택 필드
 */
function ColorField({ schema, value, onChange }: FieldProps) {
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      onChange(e.target.value);
    },
    [onChange]
  );

  const colorValue = typeof value === 'string' ? value : '#000000';

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        {schema.label || schema.name}
        {schema.required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <div className="flex items-center gap-2">
        <input
          type="color"
          value={colorValue}
          onChange={handleChange}
          className="w-10 h-10 p-0 border border-gray-300 dark:border-gray-600 rounded cursor-pointer"
        />
        <input
          type="text"
          value={colorValue}
          onChange={handleChange}
          placeholder="#000000"
          className="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-mono"
        />
      </div>
      {schema.description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}
    </div>
  );
}

/**
 * 클래스명 입력 필드 (Tailwind CSS 자동완성 지원 예정)
 */
function ClassNameField({ schema, value, onChange }: FieldProps) {
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLTextAreaElement>) => {
      onChange(e.target.value);
    },
    [onChange]
  );

  const stringValue = typeof value === 'string' ? value : '';

  // 클래스 목록 파싱
  const classList = stringValue
    .split(/\s+/)
    .filter(Boolean);

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        {schema.label || schema.name}
        {schema.required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <textarea
        value={stringValue}
        onChange={handleChange}
        placeholder="space-y-4 p-4 bg-white dark:bg-gray-800"
        rows={3}
        className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono resize-none"
      />

      {/* 클래스 태그 미리보기 */}
      {classList.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1">
          {classList.slice(0, 10).map((cls, index) => (
            <span
              key={index}
              className="px-1.5 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded"
            >
              {cls}
            </span>
          ))}
          {classList.length > 10 && (
            <span className="px-1.5 py-0.5 text-xs text-gray-500 dark:text-gray-400">
              +{classList.length - 10}개 더
            </span>
          )}
        </div>
      )}

      {schema.description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}
    </div>
  );
}

/**
 * 표현식 입력 필드 (데이터 바인딩)
 */
function ExpressionField({ schema, value, onChange }: FieldProps) {
  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      onChange(e.target.value);
    },
    [onChange]
  );

  const stringValue = typeof value === 'string' ? value : '';

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        {schema.label || schema.name}
        {schema.required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <div className="relative">
        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm">
          {'{{'}
        </span>
        <input
          type="text"
          value={stringValue.replace(/^\{\{|\}\}$/g, '')}
          onChange={(e) => onChange(`{{${e.target.value}}}`)}
          placeholder="expression"
          className="w-full pl-8 pr-8 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
        />
        <span className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm">
          {'}}'}
        </span>
      </div>
      {schema.description && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}
    </div>
  );
}

/**
 * JSON 객체 필드
 */
function ObjectField({ schema, value, onChange }: FieldProps) {
  const [error, setError] = useState<string | null>(null);

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLTextAreaElement>) => {
      const text = e.target.value;
      try {
        const parsed = JSON.parse(text);
        onChange(parsed);
        setError(null);
      } catch {
        // JSON 파싱 실패 - 텍스트는 유지하지만 에러 표시
        setError('유효한 JSON이 아닙니다');
      }
    },
    [onChange]
  );

  const jsonString = useMemo(() => {
    try {
      return JSON.stringify(value, null, 2);
    } catch {
      return '';
    }
  }, [value]);

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        {schema.label || schema.name}
        {schema.required && <span className="text-red-500 ml-1">*</span>}
      </label>
      <textarea
        value={jsonString}
        onChange={handleChange}
        placeholder="{}"
        rows={4}
        className={`w-full px-3 py-2 text-sm border rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono resize-none ${
          error
            ? 'border-red-500 dark:border-red-400'
            : 'border-gray-300 dark:border-gray-600'
        }`}
      />
      {error && (
        <p className="mt-1 text-xs text-red-500 dark:text-red-400">{error}</p>
      )}
      {schema.description && !error && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {schema.description}
        </p>
      )}
    </div>
  );
}

// ============================================================================
// 필드 렌더러
// ============================================================================

function renderField(schema: PropSchema, value: unknown, onChange: (value: unknown) => void) {
  const fieldProps: FieldProps = { schema, value, onChange };

  switch (schema.type) {
    case 'text':
    case 'textarea':
      return <TextField {...fieldProps} />;
    case 'number':
      return <NumberField {...fieldProps} />;
    case 'boolean':
      return <BooleanField {...fieldProps} />;
    case 'select':
      return <SelectField {...fieldProps} />;
    case 'color':
      return <ColorField {...fieldProps} />;
    case 'className':
      return <ClassNameField {...fieldProps} />;
    case 'expression':
      return <ExpressionField {...fieldProps} />;
    case 'object':
      return <ObjectField {...fieldProps} />;
    default:
      return <TextField {...fieldProps} />;
  }
}

// ============================================================================
// PropsEditor 메인 컴포넌트
// ============================================================================

export function PropsEditor({ component, onChange }: PropsEditorProps) {
  const { getPropsSchema } = useComponentMetadata();

  // 컴포넌트의 props 스키마 가져오기
  const propsSchema = useMemo(
    () => getPropsSchema(component.name),
    [component.name, getPropsSchema]
  );

  // 현재 props 값
  const currentProps = component.props || {};

  // prop 값 변경 핸들러
  const handlePropChange = useCallback(
    (propName: string, value: unknown) => {
      const newProps = {
        ...currentProps,
        [propName]: value,
      };

      // undefined 값은 제거
      if (value === undefined || value === '') {
        delete newProps[propName];
      }

      onChange({ props: newProps });
      logger.log('Prop changed:', propName, value);
    },
    [currentProps, onChange]
  );

  // text 속성 변경 핸들러
  const handleTextChange = useCallback(
    (value: string) => {
      onChange({ text: value || undefined });
      logger.log('Text changed:', value);
    },
    [onChange]
  );

  return (
    <div className="p-4 space-y-4">
      {/* text 속성 (별도 처리) */}
      <div>
        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
          텍스트 (text)
        </label>
        <input
          type="text"
          value={component.text || ''}
          onChange={(e) => handleTextChange(e.target.value)}
          placeholder="컴포넌트 텍스트 내용"
          className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
        />
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          $t:key 또는 {'{{expression}}'} 사용 가능
        </p>
      </div>

      <hr className="border-gray-200 dark:border-gray-700" />

      {/* 스키마 기반 props 필드들 */}
      {propsSchema.length > 0 ? (
        propsSchema.map((schema) => (
          <div key={schema.name}>
            {renderField(
              schema,
              currentProps[schema.name],
              (value) => handlePropChange(schema.name, value)
            )}
          </div>
        ))
      ) : (
        <div className="text-center py-4 text-gray-400 dark:text-gray-500">
          <p className="text-sm">
            이 컴포넌트의 props 스키마가 정의되지 않았습니다.
          </p>
          <p className="text-xs mt-1">
            Raw JSON으로 편집하세요.
          </p>
        </div>
      )}

      <hr className="border-gray-200 dark:border-gray-700" />

      {/* 모든 props Raw 편집 */}
      <div>
        <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase mb-2">
          모든 Props (Raw JSON)
        </h4>
        <ObjectField
          schema={{
            name: 'props',
            type: 'object',
            label: 'Props',
            description: '컴포넌트의 모든 속성을 JSON으로 직접 편집',
          }}
          value={currentProps}
          onChange={(value) => {
            if (typeof value === 'object' && value !== null) {
              onChange({ props: value as Record<string, unknown> });
            }
          }}
        />
      </div>
    </div>
  );
}

// ============================================================================
// Export
// ============================================================================

export default PropsEditor;
