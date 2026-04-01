/**
 * ConditionsEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 조건/반복 에디터 컴포넌트
 *
 * 역할:
 * - 조건부 렌더링 (if) 설정
 * - 반복 렌더링 (iteration) 설정
 * - 표현식 빌더 지원
 *
 * Phase 2: 핵심 편집 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import type { ComponentDefinition } from '../../types/editor';
import { useEditorState } from '../../hooks/useEditorState';

// ============================================================================
// 타입 정의
// ============================================================================

interface ConditionsEditorProps {
  /** 편집 대상 컴포넌트 */
  component: ComponentDefinition;
  /** 변경 시 콜백 */
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

interface IterationConfig {
  source: string;
  item_var: string;
  index_var?: string;
}

/** 자주 사용되는 조건 표현식 템플릿 */
const CONDITION_TEMPLATES = [
  { label: '상태 확인', value: '{{_local.isVisible}}', description: '로컬 상태가 참일 때' },
  { label: '값 존재 확인', value: '{{data?.length > 0}}', description: '데이터가 존재할 때' },
  { label: '권한 확인', value: '{{_global.user?.permissions?.includes("admin")}}', description: '관리자 권한이 있을 때' },
  { label: '로딩 완료', value: '{{!_local.isLoading}}', description: '로딩이 완료되었을 때' },
  { label: '에러 없음', value: '{{!_local.error}}', description: '에러가 없을 때' },
];

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ConditionsEditor({ component, onChange }: ConditionsEditorProps) {
  const [showIfBuilder, setShowIfBuilder] = useState(false);
  const [showIterationEditor, setShowIterationEditor] = useState(false);

  // 데이터 소스 목록 가져오기
  const layoutData = useEditorState((state) => state.layoutData);
  const mockDataContext = useEditorState((state) => state.mockDataContext);

  // 사용 가능한 반복 소스
  const availableSources = useMemo(() => {
    const sources: { label: string; value: string; description: string }[] = [];

    // 데이터 소스에서 배열 데이터 추출
    if (layoutData?.data_sources) {
      layoutData.data_sources.forEach((ds) => {
        sources.push({
          label: ds.id,
          value: `{{${ds.id}.data}}`,
          description: `데이터 소스: ${ds.id}`,
        });
      });
    }

    // Mock 데이터 컨텍스트에서 배열 찾기
    if (mockDataContext._global) {
      Object.keys(mockDataContext._global).forEach((key) => {
        if (Array.isArray(mockDataContext._global[key])) {
          sources.push({
            label: `_global.${key}`,
            value: `{{_global.${key}}}`,
            description: '전역 상태 배열',
          });
        }
      });
    }

    if (mockDataContext._local) {
      Object.keys(mockDataContext._local).forEach((key) => {
        if (Array.isArray(mockDataContext._local[key])) {
          sources.push({
            label: `_local.${key}`,
            value: `{{_local.${key}}}`,
            description: '로컬 상태 배열',
          });
        }
      });
    }

    return sources;
  }, [layoutData, mockDataContext]);

  // if 조건 변경
  const handleIfChange = useCallback(
    (value: string) => {
      onChange({ if: value || undefined });
    },
    [onChange]
  );

  // iteration 변경
  const handleIterationChange = useCallback(
    (updates: Partial<IterationConfig> | null) => {
      if (updates === null) {
        onChange({ iteration: undefined });
      } else {
        const currentIteration = component.iteration || {
          source: '',
          item_var: 'item',
          index_var: 'index',
        };
        onChange({
          iteration: { ...currentIteration, ...updates } as IterationConfig,
        });
      }
    },
    [component.iteration, onChange]
  );

  // iteration 활성화
  const handleEnableIteration = useCallback(() => {
    onChange({
      iteration: {
        source: '',
        item_var: 'item',
        index_var: 'index',
      },
    });
    setShowIterationEditor(true);
  }, [onChange]);

  return (
    <div className="p-4 space-y-6">
      {/* 조건부 렌더링 (if) */}
      <div>
        <div className="flex items-center justify-between mb-2">
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            조건부 렌더링 (if)
          </h4>
          {component.if && (
            <button
              onClick={() => handleIfChange('')}
              className="text-xs text-red-500 hover:text-red-700"
              title="조건 삭제"
            >
              삭제
            </button>
          )}
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
          조건이 참일 때만 컴포넌트를 렌더링합니다.
        </p>

        <div className="space-y-2">
          <input
            type="text"
            value={component.if || ''}
            onChange={(e) => handleIfChange(e.target.value)}
            placeholder="{{_local.showComponent}}"
            className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
          />

          {/* 빠른 템플릿 버튼 */}
          <div className="flex items-center gap-1 flex-wrap">
            <span className="text-xs text-gray-500 dark:text-gray-400">빠른 입력:</span>
            {CONDITION_TEMPLATES.slice(0, 3).map((template) => (
              <button
                key={template.value}
                onClick={() => handleIfChange(template.value)}
                className="px-2 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                title={template.description}
              >
                {template.label}
              </button>
            ))}
            <button
              onClick={() => setShowIfBuilder(!showIfBuilder)}
              className="px-2 py-0.5 text-xs text-blue-600 dark:text-blue-400 hover:underline"
            >
              {showIfBuilder ? '닫기' : '더보기...'}
            </button>
          </div>

          {/* 확장 템플릿 */}
          {showIfBuilder && (
            <div className="mt-2 p-2 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-600 dark:text-gray-400 mb-2">조건 템플릿 선택:</p>
              <div className="space-y-1">
                {CONDITION_TEMPLATES.map((template) => (
                  <button
                    key={template.value}
                    onClick={() => {
                      handleIfChange(template.value);
                      setShowIfBuilder(false);
                    }}
                    className="w-full text-left px-2 py-1 text-xs hover:bg-gray-200 dark:hover:bg-gray-700 rounded transition-colors"
                  >
                    <span className="font-medium text-gray-700 dark:text-gray-300">
                      {template.label}
                    </span>
                    <span className="text-gray-500 dark:text-gray-400 ml-2">
                      {template.description}
                    </span>
                    <code className="block text-xs text-blue-600 dark:text-blue-400 mt-0.5">
                      {template.value}
                    </code>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* if 조건 설정됨 표시 */}
        {component.if && (
          <div className="mt-2 flex items-center gap-2 text-xs">
            <span className="px-2 py-0.5 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 rounded">
              ⚠️ 조건부 렌더링 활성화
            </span>
          </div>
        )}
      </div>

      {/* 구분선 */}
      <hr className="border-gray-200 dark:border-gray-700" />

      {/* 반복 렌더링 (iteration) */}
      <div>
        <div className="flex items-center justify-between mb-2">
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            반복 렌더링 (iteration)
          </h4>
          {component.iteration && (
            <button
              onClick={() => handleIterationChange(null)}
              className="text-xs text-red-500 hover:text-red-700"
              title="반복 삭제"
            >
              삭제
            </button>
          )}
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
          데이터 배열을 순회하며 컴포넌트를 반복 렌더링합니다.
        </p>

        {component.iteration ? (
          <div className="space-y-3">
            {/* Source */}
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                Source (반복할 배열)
              </label>
              <input
                type="text"
                value={component.iteration.source || ''}
                onChange={(e) => handleIterationChange({ source: e.target.value })}
                placeholder="{{users.data}}"
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
              />
              {/* 사용 가능한 소스 버튼 */}
              {availableSources.length > 0 && (
                <div className="flex items-center gap-1 flex-wrap mt-1">
                  <span className="text-xs text-gray-500 dark:text-gray-400">사용 가능:</span>
                  {availableSources.slice(0, 3).map((source) => (
                    <button
                      key={source.value}
                      onClick={() => handleIterationChange({ source: source.value })}
                      className="px-2 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                      title={source.description}
                    >
                      {source.label}
                    </button>
                  ))}
                </div>
              )}
            </div>

            {/* Item/Index 변수 */}
            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Item 변수명
                </label>
                <input
                  type="text"
                  value={component.iteration.item_var || 'item'}
                  onChange={(e) => handleIterationChange({ item_var: e.target.value || 'item' })}
                  placeholder="item"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Index 변수명
                </label>
                <input
                  type="text"
                  value={component.iteration.index_var || 'index'}
                  onChange={(e) => handleIterationChange({ index_var: e.target.value || 'index' })}
                  placeholder="index"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
                />
              </div>
            </div>

            {/* 사용법 힌트 */}
            <div className="p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
              <p className="font-medium mb-1">사용법:</p>
              <code className="block">
                {`{{${component.iteration.item_var || 'item'}.속성}}`} - 현재 아이템 접근
              </code>
              <code className="block">
                {`{{${component.iteration.index_var || 'index'}}}`} - 현재 인덱스 접근
              </code>
            </div>

            {/* iteration 활성화 표시 */}
            <div className="flex items-center gap-2 text-xs">
              <span className="px-2 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded">
                🔁 반복 렌더링 활성화
              </span>
              {component.iteration.source && (
                <span className="text-gray-500 dark:text-gray-400">
                  소스: {component.iteration.source}
                </span>
              )}
            </div>
          </div>
        ) : (
          <div className="text-center py-4">
            <p className="text-gray-400 dark:text-gray-500 mb-2">반복 설정이 없습니다</p>
            <button
              onClick={handleEnableIteration}
              className="px-3 py-1.5 text-sm bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded hover:bg-purple-200 dark:hover:bg-purple-900/50 transition-colors"
            >
              + 반복 렌더링 추가
            </button>
          </div>
        )}
      </div>

      {/* 조합 경고 */}
      {component.if && component.iteration && (
        <div className="p-2 bg-amber-50 dark:bg-amber-900/20 rounded border border-amber-200 dark:border-amber-800 text-xs text-amber-700 dark:text-amber-300">
          <p className="font-medium">⚠️ 조건 + 반복 조합</p>
          <p className="mt-1">
            조건(if)과 반복(iteration)이 함께 설정되어 있습니다.
            조건이 먼저 평가되고, 참일 때만 반복이 실행됩니다.
          </p>
        </div>
      )}
    </div>
  );
}

export default ConditionsEditor;
