/**
 * ModalEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 모달 편집기
 *
 * 역할:
 * - 레이아웃의 modals 배열 관리
 * - 모달 컴포넌트 정의 (id, props, children)
 * - 모달 열기/닫기 액션 설정
 *
 * Phase 3: 고급 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import type { ComponentDefinition } from '../../types/editor';

// ============================================================================
// 타입 정의
// ============================================================================

export interface ModalDefinition extends ComponentDefinition {
  id: string;
  type: 'composite';
  name: 'Modal';
  props?: {
    title?: string;
    width?: string;
    size?: 'small' | 'medium' | 'large' | 'full';
    closable?: boolean;
  };
  children?: ComponentDefinition[];
}

export interface ModalEditorProps {
  /** 현재 modals 배열 */
  modals: ModalDefinition[];
  /** 변경 시 콜백 */
  onChange: (modals: ModalDefinition[]) => void;
}

/** 모달 크기 옵션 */
const MODAL_SIZES = [
  { value: 'small', label: '작음', width: '400px' },
  { value: 'medium', label: '중간', width: '600px' },
  { value: 'large', label: '큼', width: '800px' },
  { value: 'full', label: '전체', width: '100%' },
] as const;

// ============================================================================
// 모달 편집기 아이템
// ============================================================================

interface ModalEditorItemProps {
  modal: ModalDefinition;
  index: number;
  isOpen: boolean;
  onToggle: () => void;
  onChange: (modal: ModalDefinition) => void;
  onDelete: () => void;
  existingIds: string[];
}

function ModalEditorItem({
  modal,
  index,
  isOpen,
  onToggle,
  onChange,
  onDelete,
  existingIds,
}: ModalEditorItemProps) {
  const [idError, setIdError] = useState<string | null>(null);

  // ID 변경 시 유효성 검사
  const handleIdChange = useCallback(
    (newId: string) => {
      if (existingIds.includes(newId) && newId !== modal.id) {
        setIdError('이미 사용 중인 ID입니다');
      } else if (!newId) {
        setIdError('ID는 필수입니다');
      } else if (!/^[a-z][a-z0-9_]*$/i.test(newId)) {
        setIdError('ID는 영문자로 시작하고 영문, 숫자, 언더스코어만 허용');
      } else {
        setIdError(null);
      }
      onChange({ ...modal, id: newId });
    },
    [modal, existingIds, onChange]
  );

  // Props 변경 핸들러
  const handlePropsChange = useCallback(
    (key: keyof ModalDefinition['props'], value: any) => {
      const newProps = { ...modal.props, [key]: value };
      // 빈 값 제거
      if (value === '' || value === undefined) {
        delete newProps[key];
      }
      onChange({ ...modal, props: Object.keys(newProps).length ? newProps : undefined });
    },
    [modal, onChange]
  );

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-750 transition-colors"
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span className="text-gray-500">📦</span>
          <span className="text-xs font-mono bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 px-1.5 py-0.5 rounded">
            {modal.id || '(미정의)'}
          </span>
          {modal.props?.title && (
            <span className="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[200px]">
              {modal.props.title}
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {modal.props?.size && (
            <span className="px-1.5 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded">
              {MODAL_SIZES.find((s) => s.value === modal.props?.size)?.label || modal.props.size}
            </span>
          )}
          <button
            onClick={(e) => {
              e.stopPropagation();
              onDelete();
            }}
            className="text-xs text-red-500 hover:text-red-700"
          >
            삭제
          </button>
          <span className={`text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}>
            ▼
          </span>
        </div>
      </div>

      {/* 편집 폼 */}
      {isOpen && (
        <div className="p-3 space-y-4 border-t border-gray-200 dark:border-gray-700">
          {/* ID */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              모달 ID <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              value={modal.id}
              onChange={(e) => handleIdChange(e.target.value)}
              placeholder="confirm_modal"
              className={`w-full px-3 py-2 text-sm border rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white ${
                idError
                  ? 'border-red-500 dark:border-red-400'
                  : 'border-gray-300 dark:border-gray-600'
              }`}
            />
            {idError && <p className="text-xs text-red-500 mt-1">{idError}</p>}
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
              openModal 핸들러의 target으로 사용됩니다
            </p>
          </div>

          {/* Title */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              제목
            </label>
            <input
              type="text"
              value={modal.props?.title || ''}
              onChange={(e) => handlePropsChange('title', e.target.value)}
              placeholder="$t:modals.confirm_title"
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            />
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
              다국어 키 ($t:...) 또는 직접 텍스트
            </p>
          </div>

          {/* Size */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              크기
            </label>
            <select
              value={modal.props?.size || 'medium'}
              onChange={(e) => handlePropsChange('size', e.target.value)}
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            >
              {MODAL_SIZES.map((size) => (
                <option key={size.value} value={size.value}>
                  {size.label} ({size.width})
                </option>
              ))}
            </select>
          </div>

          {/* Custom Width */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              커스텀 너비 (선택)
            </label>
            <input
              type="text"
              value={modal.props?.width || ''}
              onChange={(e) => handlePropsChange('width', e.target.value)}
              placeholder="600px"
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            />
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
              크기 프리셋 대신 직접 너비 지정 (예: 600px, 50%)
            </p>
          </div>

          {/* Closable */}
          <div>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={modal.props?.closable !== false}
                onChange={(e) =>
                  handlePropsChange('closable', e.target.checked ? undefined : false)
                }
                className="rounded border-gray-300 dark:border-gray-600"
              />
              <span className="text-sm text-gray-700 dark:text-gray-300">닫기 버튼 표시</span>
            </label>
          </div>

          {/* Children 미리보기 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              본문 컴포넌트
            </label>
            {modal.children && modal.children.length > 0 ? (
              <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs text-gray-600 dark:text-gray-400">
                {modal.children.length}개의 자식 컴포넌트
                <ul className="mt-1 ml-2 list-disc list-inside">
                  {modal.children.slice(0, 5).map((child, i) => (
                    <li key={i}>
                      {child.name} ({child.id})
                    </li>
                  ))}
                  {modal.children.length > 5 && (
                    <li>... 외 {modal.children.length - 5}개</li>
                  )}
                </ul>
              </div>
            ) : (
              <p className="text-xs text-gray-500 dark:text-gray-400">
                본문 컴포넌트 없음 - 레이아웃 트리에서 추가하세요
              </p>
            )}
          </div>

          {/* 사용 방법 안내 */}
          <div className="p-3 bg-purple-50 dark:bg-purple-900/20 rounded text-xs text-purple-700 dark:text-purple-300">
            <p className="font-medium mb-2">모달 사용 방법:</p>
            <div className="text-purple-600 dark:text-purple-400 space-y-1">
              <p>열기: <code className="bg-purple-100 dark:bg-purple-900/40 px-1 rounded">
                {`{ "handler": "openModal", "target": "${modal.id}" }`}
              </code></p>
              <p>닫기: <code className="bg-purple-100 dark:bg-purple-900/40 px-1 rounded">
                {`{ "handler": "closeModal" }`}
              </code></p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ModalEditor({ modals, onChange }: ModalEditorProps) {
  const [openIndices, setOpenIndices] = useState<Set<number>>(new Set());

  // 기존 ID 목록 (중복 검사용)
  const existingIds = useMemo(
    () => modals.map((m) => m.id).filter(Boolean),
    [modals]
  );

  // 모달 토글
  const handleToggle = useCallback((index: number) => {
    setOpenIndices((prev) => {
      const next = new Set(prev);
      if (next.has(index)) {
        next.delete(index);
      } else {
        next.add(index);
      }
      return next;
    });
  }, []);

  // 모달 변경
  const handleChange = useCallback(
    (index: number, modal: ModalDefinition) => {
      const newModals = [...modals];
      newModals[index] = modal;
      onChange(newModals);
    },
    [modals, onChange]
  );

  // 모달 삭제
  const handleDelete = useCallback(
    (index: number) => {
      const newModals = modals.filter((_, i) => i !== index);
      onChange(newModals);
      setOpenIndices((prev) => {
        const next = new Set<number>();
        prev.forEach((i) => {
          if (i < index) next.add(i);
          else if (i > index) next.add(i - 1);
        });
        return next;
      });
    },
    [modals, onChange]
  );

  // 새 모달 추가
  const handleAdd = useCallback(() => {
    const newIndex = modals.length;
    const newId = `modal${newIndex + 1}`;
    const newModal: ModalDefinition = {
      id: newId,
      type: 'composite',
      name: 'Modal',
      props: {
        title: '새 모달',
        size: 'medium',
      },
      children: [],
    };
    onChange([...modals, newModal]);
    setOpenIndices((prev) => new Set(prev).add(newIndex));
  }, [modals, onChange]);

  return (
    <div className="p-4 space-y-4">
      {/* 헤더 */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            모달
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            레이아웃에서 사용할 모달을 정의합니다.
          </p>
        </div>
        <span className="text-xs text-gray-500 dark:text-gray-400">
          {modals.length}개
        </span>
      </div>

      {/* 모달 목록 */}
      {modals.length > 0 ? (
        <div className="space-y-2">
          {modals.map((modal, index) => (
            <ModalEditorItem
              key={`${modal.id}-${index}`}
              modal={modal}
              index={index}
              isOpen={openIndices.has(index)}
              onToggle={() => handleToggle(index)}
              onChange={(updated) => handleChange(index, updated)}
              onDelete={() => handleDelete(index)}
              existingIds={existingIds.filter((id) => id !== modal.id)}
            />
          ))}
        </div>
      ) : (
        <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded">
          모달이 없습니다.
          <br />
          아래 버튼으로 추가하세요.
        </div>
      )}

      {/* 추가 버튼 */}
      <button
        onClick={handleAdd}
        className="w-full px-3 py-2 text-sm bg-purple-500 text-white rounded hover:bg-purple-600 transition-colors"
      >
        + 새 모달 추가
      </button>

      {/* 도움말 */}
      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
        <p className="font-medium mb-2">모달 시스템 규칙:</p>
        <ul className="list-disc list-inside space-y-1 text-blue-600 dark:text-blue-400">
          <li>
            <strong>isOpen, onClose</strong>는 템플릿 엔진이 자동 주입
          </li>
          <li>
            <strong>_global.activeModal</strong>로 현재 열린 모달 확인
          </li>
          <li>
            모달 내 버튼에서 <strong>closeModal</strong> 핸들러로 닫기
          </li>
          <li>
            모달 내부 컴포넌트는 레이아웃 트리에서 편집
          </li>
        </ul>
      </div>

      {/* JSON 미리보기 */}
      {modals.length > 0 && (
        <div className="space-y-2">
          <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            JSON 미리보기
          </h5>
          <pre className="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400 overflow-auto max-h-48">
            {JSON.stringify(modals, null, 2)}
          </pre>
        </div>
      )}
    </div>
  );
}

export default ModalEditor;
