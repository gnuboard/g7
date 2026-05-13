/**
 * ClassMapEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기의 classMap 편집 컴포넌트
 *
 * 역할:
 * - classMap 구조 시각적 편집 (base, variants, key, default)
 * - variant 추가/삭제/수정
 * - 바인딩 표현식 입력 지원
 */

import React, { useCallback, useMemo, useState } from 'react';
import { createLogger } from '../../../../utils/Logger';

const logger = createLogger('ClassMapEditor');

// ============================================================================
// 타입 정의
// ============================================================================

/**
 * classMap 구조 타입
 */
export interface ClassMapValue {
  /** 기본 클래스 (항상 적용) */
  base?: string;

  /** variant별 클래스 매핑 */
  variants?: Record<string, string>;

  /** variant 키 결정 표현식 */
  key?: string;

  /** 기본값 (매칭되는 variant 없을 때) */
  default?: string;
}

export interface ClassMapEditorProps {
  /** classMap 값 */
  value: ClassMapValue | undefined;

  /** 변경 핸들러 */
  onChange: (value: ClassMapValue | undefined) => void;

  /** 레이블 */
  label?: string;

  /** 설명 */
  description?: string;
}

// ============================================================================
// 서브 컴포넌트들
// ============================================================================

/**
 * Variant 항목 편집기
 */
function VariantItem({
  variantKey,
  variantClass,
  onKeyChange,
  onClassChange,
  onDelete,
}: {
  variantKey: string;
  variantClass: string;
  onKeyChange: (newKey: string) => void;
  onClassChange: (newClass: string) => void;
  onDelete: () => void;
}) {
  return (
    <div className="flex items-start gap-2 p-2 bg-gray-50 dark:bg-gray-800 rounded">
      <div className="flex-1">
        <label className="block text-xs text-gray-500 dark:text-gray-400 mb-1">
          키 (variant name)
        </label>
        <input
          type="text"
          value={variantKey}
          onChange={(e) => onKeyChange(e.target.value)}
          placeholder="success, error, warning..."
          className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
        />
      </div>
      <div className="flex-[2]">
        <label className="block text-xs text-gray-500 dark:text-gray-400 mb-1">
          클래스
        </label>
        <input
          type="text"
          value={variantClass}
          onChange={(e) => onClassChange(e.target.value)}
          placeholder="bg-green-100 text-green-800"
          className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
        />
      </div>
      <button
        type="button"
        onClick={onDelete}
        className="mt-5 p-1 text-red-500 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded"
        title="variant 삭제"
      >
        <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
          <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
        </svg>
      </button>
    </div>
  );
}

/**
 * 미리보기 태그
 */
function ClassPreviewTags({ classes }: { classes: string }) {
  const classList = classes.split(/\s+/).filter(Boolean);

  if (classList.length === 0) return null;

  return (
    <div className="flex flex-wrap gap-1 mt-1">
      {classList.slice(0, 8).map((cls, index) => (
        <span
          key={index}
          className="px-1.5 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded"
        >
          {cls}
        </span>
      ))}
      {classList.length > 8 && (
        <span className="px-1.5 py-0.5 text-xs text-gray-500 dark:text-gray-400">
          +{classList.length - 8}개 더
        </span>
      )}
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ClassMapEditor({
  value,
  onChange,
  label = 'classMap',
  description,
}: ClassMapEditorProps) {
  const [isExpanded, setIsExpanded] = useState(!!value);

  // classMap 활성화/비활성화 토글
  const handleToggle = useCallback(() => {
    if (value) {
      // 비활성화 - classMap 제거
      onChange(undefined);
      setIsExpanded(false);
    } else {
      // 활성화 - 기본 구조 생성
      onChange({
        base: '',
        variants: {},
        key: '',
        default: '',
      });
      setIsExpanded(true);
    }
  }, [value, onChange]);

  // base 클래스 변경
  const handleBaseChange = useCallback(
    (newBase: string) => {
      onChange({
        ...value,
        base: newBase || undefined,
      });
    },
    [value, onChange]
  );

  // key 표현식 변경
  const handleKeyChange = useCallback(
    (newKey: string) => {
      onChange({
        ...value,
        key: newKey || undefined,
      });
    },
    [value, onChange]
  );

  // default 클래스 변경
  const handleDefaultChange = useCallback(
    (newDefault: string) => {
      onChange({
        ...value,
        default: newDefault || undefined,
      });
    },
    [value, onChange]
  );

  // variant 추가
  const handleAddVariant = useCallback(() => {
    const variants = value?.variants || {};
    const newKey = `variant_${Object.keys(variants).length + 1}`;
    onChange({
      ...value,
      variants: {
        ...variants,
        [newKey]: '',
      },
    });
  }, [value, onChange]);

  // variant 키 변경
  const handleVariantKeyChange = useCallback(
    (oldKey: string, newKey: string) => {
      if (!value?.variants || oldKey === newKey) return;

      const variants = { ...value.variants };
      const variantValue = variants[oldKey];
      delete variants[oldKey];
      variants[newKey] = variantValue;

      onChange({
        ...value,
        variants,
      });
    },
    [value, onChange]
  );

  // variant 클래스 변경
  const handleVariantClassChange = useCallback(
    (variantKey: string, newClass: string) => {
      onChange({
        ...value,
        variants: {
          ...value?.variants,
          [variantKey]: newClass,
        },
      });
    },
    [value, onChange]
  );

  // variant 삭제
  const handleVariantDelete = useCallback(
    (variantKey: string) => {
      if (!value?.variants) return;

      const variants = { ...value.variants };
      delete variants[variantKey];

      onChange({
        ...value,
        variants,
      });
    },
    [value, onChange]
  );

  // variants 목록
  const variantEntries = useMemo(() => {
    return Object.entries(value?.variants || {});
  }, [value?.variants]);

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
          {value && (
            <span className="px-1.5 py-0.5 text-xs bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded">
              활성화
            </span>
          )}
        </div>

        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            handleToggle();
          }}
          className={`px-2 py-1 text-xs rounded ${
            value
              ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-900/50'
              : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 hover:bg-green-200 dark:hover:bg-green-900/50'
          }`}
        >
          {value ? '제거' : '추가'}
        </button>
      </div>

      {/* 내용 */}
      {isExpanded && value && (
        <div className="p-3 space-y-4">
          {/* base 클래스 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              기본 클래스 (base)
            </label>
            <textarea
              value={value.base || ''}
              onChange={(e) => handleBaseChange(e.target.value)}
              placeholder="px-2 py-1 rounded-full text-xs font-medium"
              rows={2}
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono resize-none"
            />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              모든 상태에서 항상 적용되는 클래스
            </p>
            {value.base && <ClassPreviewTags classes={value.base} />}
          </div>

          {/* key 표현식 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              키 표현식 (key)
            </label>
            <div className="relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm">
                {'{{'}
              </span>
              <input
                type="text"
                value={(value.key || '').replace(/^\{\{|\}\}$/g, '')}
                onChange={(e) => handleKeyChange(`{{${e.target.value}}}`)}
                placeholder="row.status"
                className="w-full pl-8 pr-8 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
              />
              <span className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm">
                {'}}'}
              </span>
            </div>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              variant를 결정하는 표현식 (예: row.status, item.type)
            </p>
          </div>

          {/* variants */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
                Variants ({variantEntries.length})
              </label>
              <button
                type="button"
                onClick={handleAddVariant}
                className="px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-900/50"
              >
                + 추가
              </button>
            </div>

            {variantEntries.length > 0 ? (
              <div className="space-y-2">
                {variantEntries.map(([key, cls]) => (
                  <VariantItem
                    key={key}
                    variantKey={key}
                    variantClass={cls}
                    onKeyChange={(newKey) => handleVariantKeyChange(key, newKey)}
                    onClassChange={(newClass) => handleVariantClassChange(key, newClass)}
                    onDelete={() => handleVariantDelete(key)}
                  />
                ))}
              </div>
            ) : (
              <div className="p-4 text-center text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800 rounded">
                <p className="text-sm">variant가 없습니다</p>
                <p className="text-xs mt-1">+ 추가 버튼을 클릭하여 variant를 추가하세요</p>
              </div>
            )}
          </div>

          {/* default 클래스 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              기본값 클래스 (default)
            </label>
            <input
              type="text"
              value={value.default || ''}
              onChange={(e) => handleDefaultChange(e.target.value)}
              placeholder="bg-gray-100 text-gray-600"
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 font-mono"
            />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              매칭되는 variant가 없을 때 적용되는 클래스
            </p>
            {value.default && <ClassPreviewTags classes={value.default} />}
          </div>

          {/* 사용 예시 */}
          <div className="p-3 bg-gray-100 dark:bg-gray-900 rounded text-xs">
            <div className="font-medium text-gray-700 dark:text-gray-300 mb-2">
              생성될 JSON 미리보기:
            </div>
            <pre className="text-gray-600 dark:text-gray-400 overflow-x-auto">
              {JSON.stringify(
                {
                  classMap: {
                    ...(value.base && { base: value.base }),
                    ...(Object.keys(value.variants || {}).length > 0 && { variants: value.variants }),
                    ...(value.key && { key: value.key }),
                    ...(value.default && { default: value.default }),
                  },
                },
                null,
                2
              )}
            </pre>
          </div>
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

export default ClassMapEditor;
