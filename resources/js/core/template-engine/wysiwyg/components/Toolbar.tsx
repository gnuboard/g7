/**
 * Toolbar.tsx
 *
 * G7 위지윅 레이아웃 편집기의 툴바 컴포넌트
 *
 * 역할:
 * - 편집기 상단 도구 모음
 * - 저장, 발행, 버전 관리
 * - Undo/Redo 버튼
 * - 편집 모드 전환
 */

import React, { useCallback, useState } from 'react';
import { useEditorState, selectCanUndo, selectCanRedo } from '../hooks/useEditorState';
import { useHistory } from '../hooks/useHistory';
import type { EditMode } from '../types/editor';
import { createLogger } from '../../../utils/Logger';
import { LayoutSettingsModal } from './LayoutSettingsModal';

const logger = createLogger('Toolbar');

// ============================================================================
// 타입 정의
// ============================================================================

export interface ToolbarProps {
  /** 클래스명 */
  className?: string;

  /** 닫기 핸들러 */
  onClose?: () => void;

  /** 저장 후 콜백 */
  onSaveComplete?: () => void;
}

// ============================================================================
// 버튼 컴포넌트들
// ============================================================================

interface ToolbarButtonProps {
  icon: string;
  label: string;
  onClick: () => void;
  disabled?: boolean;
  variant?: 'default' | 'primary' | 'danger';
  loading?: boolean;
}

function ToolbarButton({
  icon,
  label,
  onClick,
  disabled = false,
  variant = 'default',
  loading = false,
}: ToolbarButtonProps) {
  const baseClasses =
    'flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded transition-colors disabled:opacity-50 disabled:cursor-not-allowed';

  const variantClasses = {
    default:
      'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700',
    primary:
      'bg-blue-500 text-white hover:bg-blue-600 dark:bg-blue-600 dark:hover:bg-blue-700',
    danger:
      'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900',
  };

  return (
    <button
      className={`${baseClasses} ${variantClasses[variant]}`}
      onClick={onClick}
      disabled={disabled || loading}
      title={label}
    >
      {loading ? (
        <span className="animate-spin">⏳</span>
      ) : (
        <span>{icon}</span>
      )}
      <span className="hidden sm:inline">{label}</span>
    </button>
  );
}

interface ToolbarIconButtonProps {
  icon: string;
  label: string;
  onClick: () => void;
  disabled?: boolean;
  active?: boolean;
}

function ToolbarIconButton({
  icon,
  label,
  onClick,
  disabled = false,
  active = false,
}: ToolbarIconButtonProps) {
  return (
    <button
      className={`p-2 rounded transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
        active
          ? 'bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400'
          : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'
      }`}
      onClick={onClick}
      disabled={disabled}
      title={label}
    >
      <span>{icon}</span>
    </button>
  );
}

// ============================================================================
// 편집 모드 선택기
// ============================================================================

interface ModeSelectorProps {
  currentMode: EditMode;
  onChange: (mode: EditMode) => void;
}

function ModeSelector({ currentMode, onChange }: ModeSelectorProps) {
  const modes: { id: EditMode; icon: string; label: string }[] = [
    { id: 'visual', icon: '🎨', label: '비주얼' },
    { id: 'json', icon: '📝', label: 'JSON' },
    { id: 'split', icon: '⬛', label: '분할' },
  ];

  return (
    <div className="flex items-center bg-gray-100 dark:bg-gray-800 rounded-lg p-0.5">
      {modes.map((mode) => (
        <button
          key={mode.id}
          className={`flex items-center gap-1 px-3 py-1 text-sm rounded transition-colors ${
            currentMode === mode.id
              ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
              : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
          }`}
          onClick={() => onChange(mode.id)}
          title={`${mode.label} 모드`}
        >
          <span>{mode.icon}</span>
          <span className="hidden md:inline">{mode.label}</span>
        </button>
      ))}
    </div>
  );
}

// ============================================================================
// 상태 표시
// ============================================================================

interface StatusIndicatorProps {
  hasChanges: boolean;
  isSaving: boolean;
  lastSaved?: Date;
}

function StatusIndicator({ hasChanges, isSaving, lastSaved }: StatusIndicatorProps) {
  if (isSaving) {
    return (
      <div className="flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400">
        <span className="animate-spin">⏳</span>
        <span>저장 중...</span>
      </div>
    );
  }

  if (hasChanges) {
    return (
      <div className="flex items-center gap-1 text-sm text-orange-600 dark:text-orange-400">
        <span>🔶</span>
        <span>변경사항 있음</span>
      </div>
    );
  }

  if (lastSaved) {
    return (
      <div className="flex items-center gap-1 text-sm text-green-600 dark:text-green-400">
        <span>✅</span>
        <span>저장됨</span>
        <span className="text-gray-400 dark:text-gray-500 text-xs">
          ({lastSaved.toLocaleTimeString()})
        </span>
      </div>
    );
  }

  return null;
}

// ============================================================================
// 버전 드롭다운
// ============================================================================

interface VersionDropdownProps {
  currentVersion?: string;
  onVersionSelect: (versionId: string) => void;
}

function VersionDropdown({ currentVersion, onVersionSelect }: VersionDropdownProps) {
  const [isOpen, setIsOpen] = useState(false);

  // TODO: 버전 목록 로드

  return (
    <div className="relative">
      <button
        className="flex items-center gap-1 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
        onClick={() => setIsOpen(!isOpen)}
      >
        <span>📋</span>
        <span className="hidden sm:inline">버전</span>
        {currentVersion && (
          <span className="text-xs text-gray-500 dark:text-gray-400">
            ({currentVersion})
          </span>
        )}
        <span className="text-xs">{isOpen ? '▲' : '▼'}</span>
      </button>

      {isOpen && (
        <div className="absolute top-full right-0 mt-1 w-64 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
          <div className="p-3">
            <p className="text-xs text-gray-500 dark:text-gray-400 text-center">
              버전 관리 (Phase 4)
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// Toolbar 메인 컴포넌트
// ============================================================================

export function Toolbar({ className = '', onClose, onSaveComplete }: ToolbarProps) {
  // Zustand 상태
  const layoutData = useEditorState((state) => state.layoutData);
  const editMode = useEditorState((state) => state.editMode);
  const hasChanges = useEditorState((state) => state.hasChanges);
  const loadingState = useEditorState((state) => state.loadingState);
  const setEditMode = useEditorState((state) => state.setEditMode);
  const saveLayout = useEditorState((state) => state.saveLayout);
  const canUndo = useEditorState(selectCanUndo);
  const canRedo = useEditorState(selectCanRedo);

  // History 훅
  const { undo, redo, historySize, undoStackSize, redoStackSize } = useHistory();

  // 로컬 상태
  const [lastSaved, setLastSaved] = useState<Date | undefined>(undefined);
  const [showSettingsModal, setShowSettingsModal] = useState(false);

  const isSaving = loadingState === 'saving';

  // 저장 핸들러
  const handleSave = useCallback(async () => {
    try {
      await saveLayout('Manual save');
      setLastSaved(new Date());
      onSaveComplete?.();
      logger.log('Layout saved');
    } catch (error) {
      logger.error('Save failed:', error);
    }
  }, [saveLayout, onSaveComplete]);

  // Undo 핸들러
  const handleUndo = useCallback(() => {
    if (canUndo) {
      undo();
      logger.log('Undo performed');
    }
  }, [canUndo, undo]);

  // Redo 핸들러
  const handleRedo = useCallback(() => {
    if (canRedo) {
      redo();
      logger.log('Redo performed');
    }
  }, [canRedo, redo]);

  // 닫기 핸들러
  const handleClose = useCallback(() => {
    if (hasChanges) {
      const confirmed = window.confirm(
        '저장되지 않은 변경사항이 있습니다. 정말 닫으시겠습니까?'
      );
      if (!confirmed) return;
    }
    onClose?.();
  }, [hasChanges, onClose]);

  // 발행 핸들러
  const handlePublish = useCallback(async () => {
    // TODO: 발행 워크플로우 구현 (Phase 4)
    logger.log('Publish requested');
    alert('발행 기능은 Phase 4에서 구현됩니다.');
  }, []);

  // 버전 선택 핸들러
  const handleVersionSelect = useCallback((versionId: string) => {
    // TODO: 버전 로드 구현 (Phase 4)
    logger.log('Version selected:', versionId);
  }, []);

  return (
    <div
      className={`flex items-center justify-between px-4 py-2 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 ${className}`}
    >
      {/* 좌측: 로고 및 레이아웃 정보 */}
      <div className="flex items-center gap-4">
        {/* 로고 */}
        <div className="flex items-center gap-2">
          <span className="text-xl">✏️</span>
          <div>
            <h1 className="text-sm font-semibold text-gray-900 dark:text-white">
              위지윅 편집기
            </h1>
            {layoutData && (
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {layoutData.layout_name}
              </p>
            )}
          </div>
        </div>

        {/* 구분선 */}
        <div className="h-6 w-px bg-gray-200 dark:bg-gray-700" />

        {/* Undo/Redo */}
        <div className="flex items-center gap-1">
          <ToolbarIconButton
            icon="↩️"
            label={`실행 취소 (${undoStackSize})`}
            onClick={handleUndo}
            disabled={!canUndo}
          />
          <ToolbarIconButton
            icon="↪️"
            label={`다시 실행 (${redoStackSize})`}
            onClick={handleRedo}
            disabled={!canRedo}
          />
        </div>

        {/* 구분선 */}
        <div className="h-6 w-px bg-gray-200 dark:bg-gray-700" />

        {/* 편집 모드 선택기 */}
        <ModeSelector currentMode={editMode} onChange={setEditMode} />
      </div>

      {/* 중앙: 상태 표시 */}
      <div className="flex-1 flex justify-center">
        <StatusIndicator
          hasChanges={hasChanges}
          isSaving={isSaving}
          lastSaved={lastSaved}
        />
      </div>

      {/* 우측: 액션 버튼 */}
      <div className="flex items-center gap-2">
        {/* 레이아웃 설정 버튼 */}
        <ToolbarButton
          icon="⚙️"
          label="환경설정"
          onClick={() => setShowSettingsModal(true)}
        />

        {/* 버전 드롭다운 */}
        <VersionDropdown
          currentVersion={layoutData?.version}
          onVersionSelect={handleVersionSelect}
        />

        {/* 구분선 */}
        <div className="h-6 w-px bg-gray-200 dark:bg-gray-700" />

        {/* 저장 버튼 */}
        <ToolbarButton
          icon="💾"
          label="저장"
          onClick={handleSave}
          disabled={!hasChanges}
          loading={isSaving}
        />

        {/* 발행 버튼 */}
        <ToolbarButton
          icon="🚀"
          label="발행"
          onClick={handlePublish}
          variant="primary"
          disabled={hasChanges}
        />

        {/* 구분선 */}
        <div className="h-6 w-px bg-gray-200 dark:bg-gray-700" />

        {/* 닫기 버튼 */}
        <ToolbarIconButton
          icon="✕"
          label="닫기"
          onClick={handleClose}
        />
      </div>

      {/* 레이아웃 설정 모달 */}
      <LayoutSettingsModal
        isOpen={showSettingsModal}
        onClose={() => setShowSettingsModal(false)}
      />
    </div>
  );
}

// ============================================================================
// 단축키 안내
// ============================================================================

export function KeyboardShortcutsHelp() {
  const shortcuts = [
    { keys: 'Ctrl + Z', action: '실행 취소' },
    { keys: 'Ctrl + Shift + Z', action: '다시 실행' },
    { keys: 'Ctrl + S', action: '저장' },
    { keys: 'Delete / Backspace', action: '선택 삭제' },
    { keys: 'Ctrl + D', action: '복제' },
    { keys: 'Ctrl + C', action: '복사' },
    { keys: 'Ctrl + V', action: '붙여넣기' },
    { keys: 'Escape', action: '선택 해제' },
  ];

  return (
    <div className="p-4">
      <h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-3">
        키보드 단축키
      </h3>
      <div className="space-y-2">
        {shortcuts.map(({ keys, action }) => (
          <div key={keys} className="flex items-center justify-between text-sm">
            <span className="text-gray-500 dark:text-gray-400">{action}</span>
            <kbd className="px-2 py-0.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded text-xs font-mono">
              {keys}
            </kbd>
          </div>
        ))}
      </div>
    </div>
  );
}

// ============================================================================
// Export
// ============================================================================

export default Toolbar;
