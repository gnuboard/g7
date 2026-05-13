/**
 * BindingsEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 데이터 바인딩 편집기
 *
 * 역할:
 * - 컴포넌트의 데이터 바인딩 표현식 편집
 * - 바인딩 소스 선택 (dataSources, _global, _local, route, query)
 * - 표현식 빌더 (단순 모드/고급 모드)
 * - 바인딩 미리보기
 *
 * Phase 2: 핵심 편집 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import { useEditorState } from '../../hooks/useEditorState';
import { useComponentMetadata } from '../../hooks/useComponentMetadata';
import type { ComponentDefinition, DataSource } from '../../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

export interface BindingsEditorProps {
  /** 편집할 컴포넌트 */
  component: ComponentDefinition;
  /** 변경 콜백 */
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

interface BindingEntry {
  /** 바인딩된 속성 키 */
  key: string;
  /** 바인딩 표현식 */
  value: string;
  /** 속성 유형 (prop, text, 등) */
  type: 'prop' | 'text' | 'if' | 'iteration';
}

interface BindingSource {
  /** 소스 ID */
  id: string;
  /** 표시 이름 */
  label: string;
  /** 아이콘 */
  icon: string;
  /** 소스 타입 */
  type: 'dataSource' | 'global' | 'local' | 'route' | 'query' | 'defines' | 'computed';
  /** 사용 가능한 경로 목록 */
  paths: string[];
}

type EditorMode = 'simple' | 'advanced';

// ============================================================================
// 바인딩 파서
// ============================================================================

/**
 * 문자열에서 바인딩 표현식 추출
 */
function extractBindingExpression(value: string): string | null {
  const match = value.match(/\{\{(.+?)\}\}/);
  return match ? match[1].trim() : null;
}

/**
 * 표현식이 바인딩인지 확인
 */
function isBinding(value: unknown): boolean {
  return typeof value === 'string' && value.includes('{{');
}

/**
 * 바인딩 표현식 생성
 */
function createBindingExpression(path: string): string {
  return `{{${path}}}`;
}

// ============================================================================
// 바인딩 소스 선택기
// ============================================================================

interface BindingSourceSelectorProps {
  sources: BindingSource[];
  selectedPath: string;
  onSelect: (path: string) => void;
}

function BindingSourceSelector({
  sources,
  selectedPath,
  onSelect,
}: BindingSourceSelectorProps) {
  const [expandedSource, setExpandedSource] = useState<string | null>(null);

  return (
    <div className="space-y-2">
      {sources.map((source) => (
        <div key={source.id} className="border border-gray-200 dark:border-gray-700 rounded">
          {/* 소스 헤더 */}
          <button
            className="w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            onClick={() => setExpandedSource(expandedSource === source.id ? null : source.id)}
          >
            <span>{source.icon}</span>
            <span className="flex-1 text-left font-medium text-gray-700 dark:text-gray-300">
              {source.label}
            </span>
            <span className="text-gray-400">
              {expandedSource === source.id ? '▼' : '▶'}
            </span>
          </button>

          {/* 소스 경로 목록 */}
          {expandedSource === source.id && (
            <div className="border-t border-gray-200 dark:border-gray-700 max-h-40 overflow-auto">
              {source.paths.length > 0 ? (
                source.paths.map((path) => {
                  const fullPath = source.type === 'dataSource' ? `${source.id}.${path}` : path;
                  const isSelected = selectedPath === fullPath;

                  return (
                    <button
                      key={path}
                      className={`w-full px-4 py-1.5 text-xs text-left hover:bg-gray-100 dark:hover:bg-gray-700 ${
                        isSelected
                          ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400'
                          : 'text-gray-600 dark:text-gray-400'
                      }`}
                      onClick={() => onSelect(fullPath)}
                    >
                      <code className="font-mono">{path}</code>
                    </button>
                  );
                })
              ) : (
                <div className="px-4 py-2 text-xs text-gray-400 dark:text-gray-500">
                  사용 가능한 경로가 없습니다
                </div>
              )}
            </div>
          )}
        </div>
      ))}
    </div>
  );
}

// ============================================================================
// 바인딩 편집 다이얼로그
// ============================================================================

interface BindingEditDialogProps {
  isOpen: boolean;
  propKey: string;
  currentValue: string;
  sources: BindingSource[];
  onSave: (value: string) => void;
  onCancel: () => void;
}

function BindingEditDialog({
  isOpen,
  propKey,
  currentValue,
  sources,
  onSave,
  onCancel,
}: BindingEditDialogProps) {
  const [mode, setMode] = useState<EditorMode>('simple');
  const [selectedPath, setSelectedPath] = useState<string>(() => {
    const expr = extractBindingExpression(currentValue);
    return expr || '';
  });
  const [customExpression, setCustomExpression] = useState<string>(() => {
    const expr = extractBindingExpression(currentValue);
    return expr || '';
  });

  const handleSave = useCallback(() => {
    const expression = mode === 'simple' ? selectedPath : customExpression;
    if (expression) {
      onSave(createBindingExpression(expression));
    } else {
      onSave('');
    }
  }, [mode, selectedPath, customExpression, onSave]);

  const handlePathSelect = useCallback((path: string) => {
    setSelectedPath(path);
    setCustomExpression(path);
  }, []);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-lg mx-4">
        {/* 헤더 */}
        <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
          <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
            바인딩 편집: {propKey}
          </h3>
        </div>

        {/* 모드 선택 */}
        <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
          <div className="flex gap-2">
            <button
              className={`px-3 py-1.5 text-sm rounded ${
                mode === 'simple'
                  ? 'bg-blue-500 text-white'
                  : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
              }`}
              onClick={() => setMode('simple')}
            >
              단순 모드
            </button>
            <button
              className={`px-3 py-1.5 text-sm rounded ${
                mode === 'advanced'
                  ? 'bg-blue-500 text-white'
                  : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
              }`}
              onClick={() => setMode('advanced')}
            >
              고급 모드
            </button>
          </div>
        </div>

        {/* 컨텐츠 */}
        <div className="p-4 max-h-96 overflow-auto">
          {mode === 'simple' ? (
            <div className="space-y-4">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                바인딩할 데이터 소스를 선택하세요:
              </p>
              <BindingSourceSelector
                sources={sources}
                selectedPath={selectedPath}
                onSelect={handlePathSelect}
              />
              {selectedPath && (
                <div className="mt-4 p-3 bg-gray-50 dark:bg-gray-900 rounded">
                  <label className="text-xs text-gray-500 dark:text-gray-400">미리보기:</label>
                  <code className="block mt-1 text-sm text-blue-600 dark:text-blue-400 font-mono">
                    {createBindingExpression(selectedPath)}
                  </code>
                </div>
              )}
            </div>
          ) : (
            <div className="space-y-4">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                표현식을 직접 입력하세요:
              </p>
              <div>
                <label className="block text-xs text-gray-500 dark:text-gray-400 mb-1">
                  표현식 (중괄호 없이)
                </label>
                <input
                  type="text"
                  value={customExpression}
                  onChange={(e) => setCustomExpression(e.target.value)}
                  placeholder="users?.data?.total ?? 0"
                  className="w-full px-3 py-2 text-sm font-mono border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                />
              </div>
              <div className="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                <p>지원되는 문법:</p>
                <ul className="list-disc list-inside ml-2">
                  <li>Optional Chaining: <code>users?.data</code></li>
                  <li>Nullish Coalescing: <code>value ?? 'default'</code></li>
                  <li>삼항 연산자: <code>condition ? a : b</code></li>
                </ul>
              </div>
              {customExpression && (
                <div className="mt-4 p-3 bg-gray-50 dark:bg-gray-900 rounded">
                  <label className="text-xs text-gray-500 dark:text-gray-400">미리보기:</label>
                  <code className="block mt-1 text-sm text-blue-600 dark:text-blue-400 font-mono">
                    {createBindingExpression(customExpression)}
                  </code>
                </div>
              )}
            </div>
          )}
        </div>

        {/* 푸터 */}
        <div className="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-2">
          <button
            className="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
            onClick={onCancel}
          >
            취소
          </button>
          <button
            className="px-4 py-2 text-sm bg-blue-500 text-white rounded hover:bg-blue-600"
            onClick={handleSave}
          >
            적용
          </button>
        </div>
      </div>
    </div>
  );
}

// ============================================================================
// 바인딩 항목 컴포넌트
// ============================================================================

interface BindingItemProps {
  binding: BindingEntry;
  onEdit: () => void;
  onRemove: () => void;
}

function BindingItem({ binding, onEdit, onRemove }: BindingItemProps) {
  const expression = extractBindingExpression(binding.value);

  return (
    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 group">
      <div className="flex items-start justify-between gap-2">
        <div className="flex-1 min-w-0">
          {/* 속성 이름 */}
          <div className="flex items-center gap-2">
            <span className="text-xs font-medium text-gray-700 dark:text-gray-300">
              {binding.key}
            </span>
            <span className="text-xs px-1.5 py-0.5 bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded">
              {binding.type}
            </span>
          </div>

          {/* 바인딩 표현식 */}
          <code className="block mt-1 text-xs text-blue-600 dark:text-blue-400 font-mono truncate">
            {binding.value}
          </code>

          {/* 표현식 분석 */}
          {expression && (
            <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              경로: <code className="font-mono">{expression}</code>
            </div>
          )}
        </div>

        {/* 액션 버튼 */}
        <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
          <button
            className="p-1 text-gray-400 hover:text-blue-500"
            onClick={onEdit}
            title="편집"
          >
            ✏️
          </button>
          <button
            className="p-1 text-gray-400 hover:text-red-500"
            onClick={onRemove}
            title="바인딩 제거"
          >
            🗑️
          </button>
        </div>
      </div>
    </div>
  );
}

// ============================================================================
// BindingsEditor 메인 컴포넌트
// ============================================================================

export function BindingsEditor({ component, onChange }: BindingsEditorProps) {
  const [editingProp, setEditingProp] = useState<string | null>(null);
  const [isAddingNew, setIsAddingNew] = useState(false);
  const [newPropKey, setNewPropKey] = useState('');

  // Zustand 상태에서 바인딩 소스 가져오기 (개별 상태 구독)
  const layoutData = useEditorState((state) => state.layoutData);
  const mockDataContext = useEditorState((state) => state.mockDataContext);

  // 바인딩 소스를 useMemo로 안정화
  const bindingSources = useMemo(() => {
    if (!layoutData) {
      return {
        dataSources: [] as DataSource[],
        globalState: [] as string[],
        localState: [] as string[],
        routeParams: ['id', 'slug'],
        queryParams: ['page', 'per_page', 'search', 'sort', 'order'],
        defines: {} as Record<string, unknown>,
        computed: {} as Record<string, string>,
      };
    }

    return {
      dataSources: layoutData.data_sources || [],
      globalState: Object.keys(mockDataContext._global || {}),
      localState: Object.keys(mockDataContext._local || {}),
      routeParams: ['id', 'slug'],
      queryParams: ['page', 'per_page', 'search', 'sort', 'order'],
      defines: layoutData.defines || {},
      computed: layoutData.computed || {},
    };
  }, [layoutData, mockDataContext]);

  // 컴포넌트 메타데이터
  const { getMetadata } = useComponentMetadata();
  const metadata = getMetadata(component.name);

  // 현재 바인딩 목록 추출
  const bindings = useMemo<BindingEntry[]>(() => {
    const result: BindingEntry[] = [];

    // props에서 바인딩 찾기
    if (component.props) {
      Object.entries(component.props).forEach(([key, value]) => {
        if (isBinding(value)) {
          result.push({ key, value: value as string, type: 'prop' });
        }
      });
    }

    // text 바인딩
    if (component.text && isBinding(component.text)) {
      result.push({ key: 'text', value: component.text, type: 'text' });
    }

    // if 조건 바인딩
    if (component.if && isBinding(component.if)) {
      result.push({ key: 'if', value: component.if, type: 'if' });
    }

    // iteration 소스 바인딩
    if (component.iteration?.source && isBinding(component.iteration.source)) {
      result.push({ key: 'iteration.source', value: component.iteration.source, type: 'iteration' });
    }

    return result;
  }, [component]);

  // 바인딩 가능한 props 목록 (아직 바인딩되지 않은 것들)
  const availableProps = useMemo<string[]>(() => {
    const boundKeys = new Set(bindings.filter(b => b.type === 'prop').map(b => b.key));
    const allProps = metadata?.props ? Object.keys(metadata.props) : [];
    return allProps.filter(prop => !boundKeys.has(prop));
  }, [bindings, metadata]);

  // 바인딩 소스 변환
  const sources = useMemo<BindingSource[]>(() => {
    const result: BindingSource[] = [];

    // 데이터 소스
    bindingSources.dataSources.forEach((ds: DataSource) => {
      result.push({
        id: ds.id,
        label: ds.id,
        icon: '📡',
        type: 'dataSource',
        paths: ['data', 'loading', 'error', 'data.items', 'data.total', 'data.per_page'],
      });
    });

    // 전역 상태
    if (bindingSources.globalState.length > 0) {
      result.push({
        id: '_global',
        label: 'Global State',
        icon: '🌐',
        type: 'global',
        paths: bindingSources.globalState.map(key => `_global.${key}`),
      });
    }

    // 로컬 상태
    if (bindingSources.localState.length > 0) {
      result.push({
        id: '_local',
        label: 'Local State',
        icon: '📍',
        type: 'local',
        paths: bindingSources.localState.map(key => `_local.${key}`),
      });
    }

    // 라우트 파라미터
    result.push({
      id: 'route',
      label: 'Route Params',
      icon: '🛤️',
      type: 'route',
      paths: bindingSources.routeParams.map(key => `route.${key}`),
    });

    // 쿼리 파라미터
    result.push({
      id: 'query',
      label: 'Query Params',
      icon: '❓',
      type: 'query',
      paths: bindingSources.queryParams.map(key => `query.${key}`),
    });

    // defines
    if (Object.keys(bindingSources.defines).length > 0) {
      result.push({
        id: 'defines',
        label: 'Defines',
        icon: '📝',
        type: 'defines',
        paths: Object.keys(bindingSources.defines).map(key => `defines.${key}`),
      });
    }

    // computed
    if (Object.keys(bindingSources.computed).length > 0) {
      result.push({
        id: 'computed',
        label: 'Computed',
        icon: '⚡',
        type: 'computed',
        paths: Object.keys(bindingSources.computed).map(key => `computed.${key}`),
      });
    }

    return result;
  }, [bindingSources]);

  // 바인딩 저장
  const handleSaveBinding = useCallback((propKey: string, value: string) => {
    const updates: Partial<ComponentDefinition> = {};

    if (propKey === 'text') {
      updates.text = value || undefined;
    } else if (propKey === 'if') {
      updates.if = value || undefined;
    } else if (propKey === 'iteration.source') {
      updates.iteration = value
        ? {
            ...component.iteration,
            source: value,
            item_var: component.iteration?.item_var || 'item',
          }
        : undefined;
    } else {
      // props 업데이트
      updates.props = {
        ...component.props,
        [propKey]: value || undefined,
      };
      // 빈 값이면 키 삭제
      if (!value && updates.props) {
        delete updates.props[propKey];
      }
    }

    onChange(updates);
    setEditingProp(null);
    setIsAddingNew(false);
    setNewPropKey('');
  }, [component, onChange]);

  // 바인딩 제거
  const handleRemoveBinding = useCallback((binding: BindingEntry) => {
    handleSaveBinding(binding.key, '');
  }, [handleSaveBinding]);

  // 새 바인딩 추가
  const handleAddNewBinding = useCallback(() => {
    if (newPropKey) {
      setEditingProp(newPropKey);
      setIsAddingNew(false);
    }
  }, [newPropKey]);

  return (
    <div className="p-4 space-y-4">
      {/* 설명 */}
      <div className="text-sm text-gray-600 dark:text-gray-400">
        <p>데이터 바인딩을 설정하여 동적 데이터를 컴포넌트에 연결합니다.</p>
        <p className="mt-1 text-xs">
          문법: <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded">{'{{expression}}'}</code>
        </p>
      </div>

      {/* 현재 바인딩 목록 */}
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            현재 바인딩 ({bindings.length})
          </h4>
          <button
            className="text-xs text-blue-500 hover:text-blue-600"
            onClick={() => setIsAddingNew(true)}
          >
            + 바인딩 추가
          </button>
        </div>

        {bindings.length > 0 ? (
          <div className="space-y-2">
            {bindings.map((binding) => (
              <BindingItem
                key={`${binding.type}-${binding.key}`}
                binding={binding}
                onEdit={() => setEditingProp(binding.key)}
                onRemove={() => handleRemoveBinding(binding)}
              />
            ))}
          </div>
        ) : (
          <div className="text-center py-8 text-gray-400 dark:text-gray-500">
            <p>설정된 바인딩이 없습니다</p>
          </div>
        )}
      </div>

      {/* 새 바인딩 추가 UI */}
      {isAddingNew && (
        <div className="p-3 border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 rounded">
          <label className="block text-xs text-gray-700 dark:text-gray-300 mb-2">
            바인딩할 속성 선택
          </label>
          <div className="flex gap-2">
            <select
              value={newPropKey}
              onChange={(e) => setNewPropKey(e.target.value)}
              className="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            >
              <option value="">속성 선택...</option>
              <option value="text">text (텍스트)</option>
              <option value="if">if (조건부 렌더링)</option>
              <option value="iteration.source">iteration.source (반복 소스)</option>
              {availableProps.map((prop) => (
                <option key={prop} value={prop}>
                  {prop}
                </option>
              ))}
            </select>
            <button
              className="px-3 py-2 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
              onClick={handleAddNewBinding}
              disabled={!newPropKey}
            >
              다음
            </button>
            <button
              className="px-3 py-2 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              onClick={() => {
                setIsAddingNew(false);
                setNewPropKey('');
              }}
            >
              취소
            </button>
          </div>
        </div>
      )}

      {/* 바인딩 편집 다이얼로그 */}
      <BindingEditDialog
        isOpen={editingProp !== null}
        propKey={editingProp || ''}
        currentValue={
          editingProp
            ? bindings.find((b) => b.key === editingProp)?.value || ''
            : ''
        }
        sources={sources}
        onSave={(value) => editingProp && handleSaveBinding(editingProp, value)}
        onCancel={() => setEditingProp(null)}
      />
    </div>
  );
}

export default BindingsEditor;
