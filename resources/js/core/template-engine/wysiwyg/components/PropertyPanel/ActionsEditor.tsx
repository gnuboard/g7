/**
 * ActionsEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 액션 에디터 컴포넌트
 *
 * 역할:
 * - 컴포넌트의 액션(이벤트 핸들러) 편집
 * - 내장 핸들러 23개 + 커스텀 핸들러 지원
 * - 후속 액션 체인 (onSuccess/onError) 설정
 * - 조건부 실행 (confirm) 설정
 *
 * Phase 2: 핵심 편집 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import type { ComponentDefinition, ActionDefinition, ActionType } from '../../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

interface ActionsEditorProps {
  /** 편집 대상 컴포넌트 */
  component: ComponentDefinition;
  /** 변경 시 콜백 */
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

/** 내장 핸들러 목록 */
const BUILT_IN_HANDLERS: { value: ActionType; label: string; description: string }[] = [
  { value: 'navigate', label: 'navigate', description: '페이지 이동' },
  { value: 'navigateBack', label: 'navigateBack', description: '브라우저 뒤로가기' },
  { value: 'navigateForward', label: 'navigateForward', description: '브라우저 앞으로가기' },
  { value: 'apiCall', label: 'apiCall', description: 'API 호출' },
  { value: 'login', label: 'login', description: '로그인 (토큰 저장)' },
  { value: 'logout', label: 'logout', description: '로그아웃' },
  { value: 'setState', label: 'setState', description: '상태 변경' },
  { value: 'setError', label: 'setError', description: '에러 상태 설정' },
  { value: 'openModal', label: 'openModal', description: '모달 열기' },
  { value: 'closeModal', label: 'closeModal', description: '모달 닫기' },
  { value: 'showAlert', label: 'showAlert', description: '알림 표시' },
  { value: 'toast', label: 'toast', description: '토스트 알림' },
  { value: 'switch', label: 'switch', description: '조건 분기' },
  { value: 'sequence', label: 'sequence', description: '순차 실행' },
  { value: 'parallel', label: 'parallel', description: '병렬 실행' },
  { value: 'showErrorPage', label: 'showErrorPage', description: '에러 페이지 표시' },
  { value: 'loadScript', label: 'loadScript', description: '외부 스크립트 로드' },
  { value: 'callExternal', label: 'callExternal', description: '외부 함수 호출' },
  { value: 'callExternalEmbed', label: 'callExternalEmbed', description: '외부 임베드' },
  { value: 'saveToLocalStorage', label: 'saveToLocalStorage', description: '로컬스토리지 저장' },
  { value: 'loadFromLocalStorage', label: 'loadFromLocalStorage', description: '로컬스토리지 로드' },
  { value: 'appendDataSource', label: 'appendDataSource', description: '데이터소스 병합 (무한스크롤용)' },
  { value: 'scrollIntoView', label: 'scrollIntoView', description: '요소로 스크롤' },
  { value: 'refetchDataSource', label: 'refetchDataSource', description: '데이터소스 재조회' },
  { value: 'remount', label: 'remount', description: '컴포넌트 재마운트' },
  { value: 'custom', label: 'custom', description: '커스텀 액션' },
];

/** 이벤트 타입 목록 */
const EVENT_TYPES = [
  { value: 'click', label: 'click', description: '클릭' },
  { value: 'change', label: 'change', description: '값 변경' },
  { value: 'input', label: 'input', description: '입력' },
  { value: 'submit', label: 'submit', description: '폼 제출' },
  { value: 'keydown', label: 'keydown', description: '키 누름' },
  { value: 'keyup', label: 'keyup', description: '키 뗌' },
  { value: 'focus', label: 'focus', description: '포커스' },
  { value: 'blur', label: 'blur', description: '블러' },
  { value: 'mount', label: 'mount', description: '마운트' },
  { value: 'unmount', label: 'unmount', description: '언마운트' },
  { value: 'scroll', label: 'scroll', description: '스크롤 (무한스크롤용)' },
];

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ActionsEditor({ component, onChange }: ActionsEditorProps) {
  const [editingIndex, setEditingIndex] = useState<number | null>(null);
  const [isAddingNew, setIsAddingNew] = useState(false);

  // 액션 배열 가져오기
  const actions = useMemo(() => {
    return (component.actions || []) as ActionDefinition[];
  }, [component.actions]);

  // 액션 추가
  const handleAddAction = useCallback(() => {
    const newAction: ActionDefinition = {
      type: 'click',
      handler: 'navigate',
      params: {},
    };
    const updatedActions = [...actions, newAction];
    onChange({ actions: updatedActions });
    setEditingIndex(updatedActions.length - 1);
    setIsAddingNew(false);
  }, [actions, onChange]);

  // 액션 삭제
  const handleDeleteAction = useCallback(
    (index: number) => {
      const updatedActions = actions.filter((_, i) => i !== index);
      onChange({ actions: updatedActions.length > 0 ? updatedActions : undefined });
      if (editingIndex === index) {
        setEditingIndex(null);
      } else if (editingIndex !== null && editingIndex > index) {
        setEditingIndex(editingIndex - 1);
      }
    },
    [actions, editingIndex, onChange]
  );

  // 액션 업데이트
  const handleUpdateAction = useCallback(
    (index: number, updates: Partial<ActionDefinition>) => {
      const updatedActions = actions.map((action, i) =>
        i === index ? { ...action, ...updates } : action
      );
      onChange({ actions: updatedActions });
    },
    [actions, onChange]
  );

  // 액션 복제
  const handleDuplicateAction = useCallback(
    (index: number) => {
      const actionToDuplicate = actions[index];
      const duplicatedAction = JSON.parse(JSON.stringify(actionToDuplicate));
      const updatedActions = [
        ...actions.slice(0, index + 1),
        duplicatedAction,
        ...actions.slice(index + 1),
      ];
      onChange({ actions: updatedActions });
    },
    [actions, onChange]
  );

  // 액션 순서 이동
  const handleMoveAction = useCallback(
    (index: number, direction: 'up' | 'down') => {
      const newIndex = direction === 'up' ? index - 1 : index + 1;
      if (newIndex < 0 || newIndex >= actions.length) return;

      const updatedActions = [...actions];
      [updatedActions[index], updatedActions[newIndex]] = [
        updatedActions[newIndex],
        updatedActions[index],
      ];
      onChange({ actions: updatedActions });

      // 편집 중인 인덱스도 업데이트
      if (editingIndex === index) {
        setEditingIndex(newIndex);
      } else if (editingIndex === newIndex) {
        setEditingIndex(index);
      }
    },
    [actions, editingIndex, onChange]
  );

  return (
    <div className="p-4 space-y-4">
      <div className="text-sm text-gray-600 dark:text-gray-400 mb-4">
        이벤트 핸들러를 설정하여 사용자 상호작용에 반응합니다.
      </div>

      {/* 현재 액션 목록 */}
      {actions.length > 0 ? (
        <div className="space-y-2">
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            등록된 액션 ({actions.length})
          </h4>
          {actions.map((action, index) => (
            <ActionItem
              key={index}
              action={action}
              index={index}
              isEditing={editingIndex === index}
              isFirst={index === 0}
              isLast={index === actions.length - 1}
              onEdit={() => setEditingIndex(editingIndex === index ? null : index)}
              onDelete={() => handleDeleteAction(index)}
              onDuplicate={() => handleDuplicateAction(index)}
              onMove={(direction) => handleMoveAction(index, direction)}
              onUpdate={(updates) => handleUpdateAction(index, updates)}
            />
          ))}
        </div>
      ) : (
        <div className="text-center py-8 text-gray-400 dark:text-gray-500">
          <p>등록된 액션이 없습니다</p>
          <p className="text-xs mt-1">아래 버튼을 클릭하여 액션을 추가하세요</p>
        </div>
      )}

      {/* 액션 추가 버튼 */}
      {isAddingNew ? (
        <div className="flex gap-2">
          <button
            onClick={handleAddAction}
            className="flex-1 px-3 py-2 bg-blue-500 text-white text-sm rounded hover:bg-blue-600 transition-colors"
          >
            기본 액션 추가
          </button>
          <button
            onClick={() => setIsAddingNew(false)}
            className="px-3 py-2 text-gray-600 dark:text-gray-400 text-sm rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
          >
            취소
          </button>
        </div>
      ) : (
        <button
          onClick={() => setIsAddingNew(true)}
          className="w-full px-3 py-2 border-2 border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 text-sm rounded hover:border-blue-400 hover:text-blue-500 transition-colors"
        >
          + 액션 추가
        </button>
      )}
    </div>
  );
}

// ============================================================================
// 액션 아이템 컴포넌트
// ============================================================================

interface ActionItemProps {
  action: ActionDefinition;
  index: number;
  isEditing: boolean;
  isFirst: boolean;
  isLast: boolean;
  onEdit: () => void;
  onDelete: () => void;
  onDuplicate: () => void;
  onMove: (direction: 'up' | 'down') => void;
  onUpdate: (updates: Partial<ActionDefinition>) => void;
}

function ActionItem({
  action,
  index,
  isEditing,
  isFirst,
  isLast,
  onEdit,
  onDelete,
  onDuplicate,
  onMove,
  onUpdate,
}: ActionItemProps) {
  // 이벤트 타입 표시 (type 또는 event)
  const eventDisplay = action.event || action.type || 'click';
  const isCustomEvent = !!action.event;

  return (
    <div
      className={`rounded border transition-colors ${
        isEditing
          ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
          : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800'
      }`}
    >
      {/* 액션 헤더 */}
      <div
        className="p-2 flex items-center gap-2 cursor-pointer"
        onClick={onEdit}
      >
        {/* 인덱스 */}
        <span className="w-5 h-5 flex items-center justify-center text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-200 dark:bg-gray-700 rounded">
          {index + 1}
        </span>

        {/* 이벤트 타입 */}
        <span
          className={`text-xs font-medium px-2 py-0.5 rounded ${
            isCustomEvent
              ? 'text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900/30'
              : 'text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/30'
          }`}
        >
          {eventDisplay}
        </span>

        <span className="text-xs text-gray-400">→</span>

        {/* 핸들러 */}
        <span className="text-xs font-medium text-green-600 dark:text-green-400">
          {action.handler}
        </span>

        {/* target이 있으면 표시 */}
        {action.target && (
          <>
            <span className="text-xs text-gray-400">:</span>
            <span className="text-xs text-gray-600 dark:text-gray-400 truncate max-w-[100px]">
              {action.target}
            </span>
          </>
        )}

        {/* 후속 액션 표시 */}
        {(action.onSuccess || action.onError) && (
          <span className="text-xs text-orange-500 dark:text-orange-400">
            +chain
          </span>
        )}

        {/* 확인 다이얼로그 표시 */}
        {action.confirm && (
          <span className="text-xs text-yellow-600 dark:text-yellow-400">
            ⚠️
          </span>
        )}

        {/* Debounce 표시 */}
        {action.debounce && (
          <span className="text-xs text-purple-500 dark:text-purple-400" title="Debounce 적용됨">
            ⏱️{typeof action.debounce === 'number' ? action.debounce : action.debounce.delay}ms
          </span>
        )}

        {/* 확장 아이콘 */}
        <span className="ml-auto text-gray-400">
          {isEditing ? '▼' : '▶'}
        </span>
      </div>

      {/* 액션 편집 패널 */}
      {isEditing && (
        <div className="p-3 border-t border-gray-200 dark:border-gray-700 space-y-3">
          {/* 이벤트 타입 선택 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              이벤트 타입
            </label>
            <div className="flex gap-2">
              <select
                value={action.event ? 'custom' : (action.type || 'click')}
                onChange={(e) => {
                  const value = e.target.value;
                  if (value === 'custom') {
                    onUpdate({ type: 'click', event: 'onChange' });
                  } else {
                    onUpdate({ type: value as any, event: undefined });
                  }
                }}
                className="flex-1 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              >
                {EVENT_TYPES.map((et) => (
                  <option key={et.value} value={et.value}>
                    {et.label} ({et.description})
                  </option>
                ))}
                <option value="custom">커스텀 이벤트</option>
              </select>
            </div>
            {action.event && (
              <input
                type="text"
                value={action.event}
                onChange={(e) => onUpdate({ event: e.target.value })}
                placeholder="이벤트명 (예: onChange, onRowAction)"
                className="mt-2 w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              />
            )}
          </div>

          {/* 핸들러 선택 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              핸들러
            </label>
            <select
              value={action.handler}
              onChange={(e) => onUpdate({ handler: e.target.value })}
              className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            >
              {BUILT_IN_HANDLERS.map((h) => (
                <option key={h.value} value={h.value}>
                  {h.label} - {h.description}
                </option>
              ))}
            </select>
          </div>

          {/* Target */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              Target (선택)
            </label>
            <input
              type="text"
              value={action.target || ''}
              onChange={(e) => onUpdate({ target: e.target.value || undefined })}
              placeholder="URL, 모달 ID, API 엔드포인트 등"
              className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            />
          </div>

          {/* Params */}
          <ParamsEditor
            params={action.params || {}}
            onChange={(params) => onUpdate({ params })}
            handler={action.handler}
          />

          {/* Confirm 메시지 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              확인 메시지 (선택)
            </label>
            <input
              type="text"
              value={action.confirm || ''}
              onChange={(e) => onUpdate({ confirm: e.target.value || undefined })}
              placeholder="실행 전 확인 대화상자 메시지"
              className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            />
          </div>

          {/* 키보드 필터 */}
          {(action.type === 'keydown' || action.type === 'keyup') && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                키 필터
              </label>
              <input
                type="text"
                value={(action as any).key || ''}
                onChange={(e) => onUpdate({ key: e.target.value || undefined } as any)}
                placeholder="Enter, Escape, ArrowDown 등"
                className="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              />
            </div>
          )}

          {/* Debounce 설정 */}
          <DebounceEditor
            debounce={action.debounce}
            onChange={(debounce) => onUpdate({ debounce })}
          />

          {/* 후속 액션 표시 (간략) */}
          {(action.onSuccess || action.onError) && (
            <div className="text-xs text-gray-500 dark:text-gray-400 p-2 bg-gray-100 dark:bg-gray-700 rounded">
              <p>
                <span className="text-green-600 dark:text-green-400">onSuccess:</span>{' '}
                {action.onSuccess
                  ? Array.isArray(action.onSuccess)
                    ? `${action.onSuccess.length}개 액션`
                    : '1개 액션'
                  : '없음'}
              </p>
              <p>
                <span className="text-red-600 dark:text-red-400">onError:</span>{' '}
                {action.onError
                  ? Array.isArray(action.onError)
                    ? `${action.onError.length}개 액션`
                    : '1개 액션'
                  : '없음'}
              </p>
            </div>
          )}

          {/* 액션 도구 버튼 */}
          <div className="flex gap-2 pt-2 border-t border-gray-200 dark:border-gray-700">
            <button
              onClick={() => onMove('up')}
              disabled={isFirst}
              className="px-2 py-1 text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white disabled:opacity-30 disabled:cursor-not-allowed"
              title="위로 이동"
            >
              ↑
            </button>
            <button
              onClick={() => onMove('down')}
              disabled={isLast}
              className="px-2 py-1 text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white disabled:opacity-30 disabled:cursor-not-allowed"
              title="아래로 이동"
            >
              ↓
            </button>
            <button
              onClick={onDuplicate}
              className="px-2 py-1 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300"
              title="복제"
            >
              복제
            </button>
            <button
              onClick={onDelete}
              className="px-2 py-1 text-xs text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 ml-auto"
              title="삭제"
            >
              삭제
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// Params 편집기 컴포넌트
// ============================================================================

interface ParamsEditorProps {
  params: Record<string, any>;
  onChange: (params: Record<string, any>) => void;
  handler: string;
}

function ParamsEditor({ params, onChange, handler }: ParamsEditorProps) {
  const [newKey, setNewKey] = useState('');
  const [newValue, setNewValue] = useState('');

  // 핸들러별 권장 파라미터
  const suggestedParams = useMemo(() => {
    switch (handler) {
      case 'navigate':
        return ['path', 'query', 'mergeQuery', 'replace'];
      case 'apiCall':
        return ['method', 'body', 'headers'];
      case 'setState':
        return ['target', 'merge'];
      case 'toast':
        return ['type', 'message'];
      case 'openModal':
        return [];
      case 'closeModal':
        return [];
      case 'showErrorPage':
        return ['errorCode', 'target', 'containerId', 'layout'];
      case 'refetchDataSource':
        return ['dataSourceId'];
      case 'remount':
        return ['componentId'];
      case 'appendDataSource':
        return ['id', 'dataPath', 'sourcePath'];
      case 'scrollIntoView':
        return ['selector', 'behavior', 'block', 'scrollContainer', 'waitForElement'];
      case 'switch':
        return ['value'];
      case 'sequence':
      case 'parallel':
        return ['actions'];
      default:
        return [];
    }
  }, [handler]);

  // 파라미터 추가
  const handleAddParam = useCallback(() => {
    if (!newKey.trim()) return;
    const updatedParams = { ...params, [newKey.trim()]: newValue };
    onChange(updatedParams);
    setNewKey('');
    setNewValue('');
  }, [params, newKey, newValue, onChange]);

  // 파라미터 삭제
  const handleDeleteParam = useCallback(
    (key: string) => {
      const { [key]: _, ...rest } = params;
      onChange(rest);
    },
    [params, onChange]
  );

  // 파라미터 업데이트
  const handleUpdateParam = useCallback(
    (key: string, value: string) => {
      const updatedParams = { ...params, [key]: value };
      onChange(updatedParams);
    },
    [params, onChange]
  );

  const paramEntries = Object.entries(params);

  return (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
        파라미터
      </label>

      {/* 기존 파라미터 목록 */}
      {paramEntries.length > 0 && (
        <div className="space-y-1 mb-2">
          {paramEntries.map(([key, value]) => (
            <div key={key} className="flex gap-1 items-center">
              <span className="text-xs text-gray-600 dark:text-gray-400 w-24 truncate">
                {key}:
              </span>
              <input
                type="text"
                value={typeof value === 'object' ? JSON.stringify(value) : String(value)}
                onChange={(e) => handleUpdateParam(key, e.target.value)}
                className="flex-1 px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              />
              <button
                onClick={() => handleDeleteParam(key)}
                className="text-red-500 hover:text-red-700 text-xs px-1"
                title="삭제"
              >
                ×
              </button>
            </div>
          ))}
        </div>
      )}

      {/* 새 파라미터 추가 */}
      <div className="flex gap-1">
        <input
          type="text"
          value={newKey}
          onChange={(e) => setNewKey(e.target.value)}
          placeholder="키"
          list={`suggested-params-${handler}`}
          className="w-24 px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
        />
        <datalist id={`suggested-params-${handler}`}>
          {suggestedParams
            .filter((p) => !params[p])
            .map((p) => (
              <option key={p} value={p} />
            ))}
        </datalist>
        <input
          type="text"
          value={newValue}
          onChange={(e) => setNewValue(e.target.value)}
          placeholder="값"
          className="flex-1 px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              handleAddParam();
            }
          }}
        />
        <button
          onClick={handleAddParam}
          disabled={!newKey.trim()}
          className="px-2 py-1 text-xs bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-500 disabled:opacity-50"
        >
          +
        </button>
      </div>

      {/* 권장 파라미터 힌트 */}
      {suggestedParams.length > 0 && (
        <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
          권장: {suggestedParams.join(', ')}
        </p>
      )}
    </div>
  );
}

// ============================================================================
// Debounce 편집기 컴포넌트
// ============================================================================

/** Debounce 설정 타입 */
type DebounceConfig =
  | number
  | {
      delay: number;
      leading?: boolean;
      trailing?: boolean;
    };

interface DebounceEditorProps {
  debounce: DebounceConfig | undefined;
  onChange: (debounce: DebounceConfig | undefined) => void;
}

function DebounceEditor({ debounce, onChange }: DebounceEditorProps) {
  const [isAdvanced, setIsAdvanced] = useState(false);

  // 현재 설정 파싱
  const currentDelay = typeof debounce === 'number' ? debounce : debounce?.delay || 0;
  const currentLeading = typeof debounce === 'object' ? debounce.leading ?? false : false;
  const currentTrailing = typeof debounce === 'object' ? debounce.trailing ?? true : true;
  const isEnabled = debounce !== undefined && currentDelay > 0;

  // debounce 활성화/비활성화
  const handleToggle = useCallback(() => {
    if (isEnabled) {
      onChange(undefined);
    } else {
      onChange(300); // 기본값 300ms
    }
  }, [isEnabled, onChange]);

  // delay 변경
  const handleDelayChange = useCallback(
    (value: string) => {
      const delay = parseInt(value, 10);
      if (isNaN(delay) || delay <= 0) {
        onChange(undefined);
        return;
      }

      if (isAdvanced) {
        onChange({
          delay,
          leading: currentLeading,
          trailing: currentTrailing,
        });
      } else {
        onChange(delay);
      }
    },
    [isAdvanced, currentLeading, currentTrailing, onChange]
  );

  // leading/trailing 변경
  const handleAdvancedChange = useCallback(
    (field: 'leading' | 'trailing', value: boolean) => {
      onChange({
        delay: currentDelay || 300,
        leading: field === 'leading' ? value : currentLeading,
        trailing: field === 'trailing' ? value : currentTrailing,
      });
    },
    [currentDelay, currentLeading, currentTrailing, onChange]
  );

  // 고급 모드 전환
  const handleToggleAdvanced = useCallback(() => {
    if (!isAdvanced && isEnabled) {
      // 단순 → 고급: 현재 delay로 객체 생성
      onChange({
        delay: currentDelay,
        leading: false,
        trailing: true,
      });
    } else if (isAdvanced && isEnabled) {
      // 고급 → 단순: delay만 유지
      onChange(currentDelay);
    }
    setIsAdvanced(!isAdvanced);
  }, [isAdvanced, isEnabled, currentDelay, onChange]);

  return (
    <div>
      <div className="flex items-center justify-between mb-1">
        <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
          Debounce (연속 호출 제어)
        </label>
        <button
          type="button"
          onClick={handleToggle}
          className={`relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
            isEnabled ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'
          }`}
        >
          <span
            className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
              isEnabled ? 'translate-x-4' : 'translate-x-0'
            }`}
          />
        </button>
      </div>

      {isEnabled && (
        <div className="space-y-2 mt-2 p-2 bg-gray-50 dark:bg-gray-800 rounded">
          {/* Delay 입력 */}
          <div className="flex items-center gap-2">
            <label className="text-xs text-gray-600 dark:text-gray-400 w-16">
              지연 시간
            </label>
            <input
              type="number"
              value={currentDelay}
              onChange={(e) => handleDelayChange(e.target.value)}
              min={0}
              step={100}
              placeholder="300"
              className="flex-1 px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            />
            <span className="text-xs text-gray-500">ms</span>
          </div>

          {/* 고급 설정 토글 */}
          <button
            type="button"
            onClick={handleToggleAdvanced}
            className="text-xs text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300"
          >
            {isAdvanced ? '▼ 고급 설정 숨기기' : '▶ 고급 설정 보기'}
          </button>

          {/* 고급 설정 */}
          {isAdvanced && (
            <div className="space-y-2 pt-2 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="debounce-leading"
                  checked={currentLeading}
                  onChange={(e) => handleAdvancedChange('leading', e.target.checked)}
                  className="h-3 w-3 rounded border-gray-300 dark:border-gray-600"
                />
                <label htmlFor="debounce-leading" className="text-xs text-gray-600 dark:text-gray-400">
                  Leading (첫 호출 즉시 실행)
                </label>
              </div>
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="debounce-trailing"
                  checked={currentTrailing}
                  onChange={(e) => handleAdvancedChange('trailing', e.target.checked)}
                  className="h-3 w-3 rounded border-gray-300 dark:border-gray-600"
                />
                <label htmlFor="debounce-trailing" className="text-xs text-gray-600 dark:text-gray-400">
                  Trailing (마지막 호출 후 실행)
                </label>
              </div>
              <p className="text-xs text-gray-400 dark:text-gray-500">
                권장: change 이벤트에 300ms, 검색에 300~500ms
              </p>
            </div>
          )}
        </div>
      )}

      {!isEnabled && (
        <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
          텍스트 입력 필드에서 연속 호출을 제어합니다
        </p>
      )}
    </div>
  );
}

export default ActionsEditor;
