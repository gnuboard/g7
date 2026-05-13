/**
 * EditorContext.tsx
 *
 * G7 위지윅 레이아웃 편집기의 React 컨텍스트
 *
 * 역할:
 * - 편집기 상태를 컴포넌트 트리에 제공
 * - 편집기 초기화 및 설정
 * - 키보드 단축키 관리
 */

import React, {
  createContext,
  useContext,
  useEffect,
  useCallback,
  useMemo,
  type ReactNode,
} from 'react';
import { useEditorState, selectCanUndo, selectCanRedo } from './hooks/useEditorState';
import type {
  EditorConfig,
  DEFAULT_EDITOR_CONFIG,
  EditorState,
  EditorActions,
  ComponentDefinition,
  LayoutData,
} from './types/editor';
import { createLogger } from '../../utils/Logger';

const logger = createLogger('EditorContext');

// ============================================================================
// 컨텍스트 타입 정의
// ============================================================================

/**
 * 편집기 컨텍스트 값
 */
export interface EditorContextValue {
  /** 편집기가 활성화되어 있는지 */
  isEditorActive: boolean;

  /** 편집기 설정 */
  config: EditorConfig;

  /** 편집기 초기화 함수 */
  initialize: (
    templateId: string,
    templateType: 'admin' | 'user',
    layoutName: string
  ) => Promise<void>;

  /** 편집기 비활성화 함수 */
  deactivate: () => void;

  /** 선택된 컴포넌트 */
  selectedComponent: ComponentDefinition | null;

  /** 레이아웃 데이터 */
  layoutData: LayoutData | null;

  /** Undo 가능 여부 */
  canUndo: boolean;

  /** Redo 가능 여부 */
  canRedo: boolean;

  /** 변경 사항 존재 여부 */
  hasChanges: boolean;

  /** 로딩 중 여부 */
  isLoading: boolean;

  /** 저장 중 여부 */
  isSaving: boolean;

  /** 에러 메시지 */
  error: string | null;
}

// ============================================================================
// 컨텍스트 생성
// ============================================================================

const EditorContext = createContext<EditorContextValue | null>(null);

// ============================================================================
// Provider Props
// ============================================================================

export interface EditorProviderProps {
  /** 자식 컴포넌트 */
  children: ReactNode;

  /** 편집기 설정 (옵션) */
  config?: Partial<EditorConfig>;

  /** 자동 초기화 여부 */
  autoInitialize?: boolean;

  /** 자동 초기화 시 사용할 템플릿 ID */
  templateId?: string;

  /** 자동 초기화 시 사용할 템플릿 타입 */
  templateType?: 'admin' | 'user';

  /** 자동 초기화 시 사용할 레이아웃 이름 */
  layoutName?: string;

  /** 초기 레이아웃 데이터 (외부에서 전달) */
  layoutData?: LayoutData;

  /** 초기 편집 모드 */
  initialEditMode?: 'visual' | 'json' | 'split';
}

// ============================================================================
// 기본 설정
// ============================================================================

const defaultConfig: EditorConfig = {
  autoSave: false,
  autoSaveInterval: 30000,
  maxHistorySize: 50,
  snapToGrid: false,
  gridSize: 8,
  keyboardShortcuts: true,
  accessibilityMode: false,
};

// ============================================================================
// Provider 컴포넌트
// ============================================================================

export function EditorProvider({
  children,
  config: configOverrides,
  autoInitialize = false,
  templateId,
  templateType,
  layoutName,
  layoutData: initialLayoutData,
  initialEditMode = 'visual',
}: EditorProviderProps) {
  // Zustand 스토어에서 상태 가져오기
  const loadLayout = useEditorState((state) => state.loadLayout);
  const setLayoutData = useEditorState((state) => state.setLayoutData);
  const setEditMode = useEditorState((state) => state.setEditMode);
  const layoutData = useEditorState((state) => state.layoutData);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const loadingState = useEditorState((state) => state.loadingState);
  const error = useEditorState((state) => state.error);
  const hasChanges = useEditorState((state) => state.hasChanges);
  const undo = useEditorState((state) => state.undo);
  const redo = useEditorState((state) => state.redo);
  const saveLayout = useEditorState((state) => state.saveLayout);
  const deleteComponent = useEditorState((state) => state.deleteComponent);
  const duplicateComponent = useEditorState((state) => state.duplicateComponent);
  const copyToClipboard = useEditorState((state) => state.copyToClipboard);
  const pasteFromClipboard = useEditorState((state) => state.pasteFromClipboard);
  const clipboard = useEditorState((state) => state.clipboard);

  // Selector 기반 상태
  const canUndo = useEditorState(selectCanUndo);
  const canRedo = useEditorState(selectCanRedo);

  // 설정 병합
  const config = useMemo(
    () => ({ ...defaultConfig, ...configOverrides }),
    [configOverrides]
  );

  // 편집기 활성화 상태
  const isEditorActive = loadingState === 'loaded';
  const isLoading = loadingState === 'loading';
  const isSaving = loadingState === 'saving';

  // 선택된 컴포넌트 가져오기
  const selectedComponent = useMemo(() => {
    if (!layoutData || !selectedComponentId) return null;

    const findComponent = (
      components: (ComponentDefinition | string)[],
      id: string
    ): ComponentDefinition | null => {
      for (const comp of components) {
        if (typeof comp === 'string') continue;
        if (comp.id === id) return comp;
        if (comp.children) {
          const found = findComponent(comp.children, id);
          if (found) return found;
        }
      }
      return null;
    };

    return findComponent(layoutData.components, selectedComponentId);
  }, [layoutData, selectedComponentId]);

  // 초기화 함수
  const initialize = useCallback(
    async (tid: string, ttype: 'admin' | 'user', lname: string) => {
      logger.log('Initializing editor:', { tid, ttype, lname });
      await loadLayout(tid, ttype, lname);
    },
    [loadLayout]
  );

  // 비활성화 함수
  const deactivate = useCallback(() => {
    logger.log('Deactivating editor');
    // 상태 초기화는 별도 처리 필요
  }, []);

  // 초기 레이아웃 데이터 설정 (외부에서 전달된 경우)
  useEffect(() => {
    if (initialLayoutData && !layoutData) {
      logger.log('Setting initial layout data from props', {
        layoutName: initialLayoutData.layout_name,
        componentsCount: initialLayoutData.components?.length || 0,
      });
      setLayoutData(initialLayoutData, templateId || 'unknown');
      setEditMode(initialEditMode);
    }
  }, [initialLayoutData, layoutData, templateId, initialEditMode, setLayoutData, setEditMode]);

  // 자동 초기화 (API를 통해 레이아웃 로드)
  useEffect(() => {
    if (autoInitialize && templateId && templateType && layoutName && !initialLayoutData) {
      initialize(templateId, templateType, layoutName).catch((err) => {
        logger.error('Auto-initialize failed:', err);
      });
    }
  }, [autoInitialize, templateId, templateType, layoutName, initialize, initialLayoutData]);

  // 키보드 단축키 처리
  useEffect(() => {
    if (!config.keyboardShortcuts || !isEditorActive) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      // 입력 필드에서는 무시
      const target = e.target as HTMLElement;
      if (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.isContentEditable
      ) {
        return;
      }

      const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
      const modKey = isMac ? e.metaKey : e.ctrlKey;

      // Undo: Ctrl/Cmd + Z
      if (modKey && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        if (canUndo) {
          undo();
          logger.log('Undo triggered');
        }
        return;
      }

      // Redo: Ctrl/Cmd + Shift + Z 또는 Ctrl/Cmd + Y
      if ((modKey && e.key === 'z' && e.shiftKey) || (modKey && e.key === 'y')) {
        e.preventDefault();
        if (canRedo) {
          redo();
          logger.log('Redo triggered');
        }
        return;
      }

      // Save: Ctrl/Cmd + S
      if (modKey && e.key === 's') {
        e.preventDefault();
        if (hasChanges) {
          saveLayout().catch((err) => {
            logger.error('Save failed:', err);
          });
        }
        return;
      }

      // Delete: Delete 또는 Backspace
      if ((e.key === 'Delete' || e.key === 'Backspace') && selectedComponentId) {
        e.preventDefault();
        deleteComponent(selectedComponentId);
        logger.log('Delete triggered');
        return;
      }

      // Duplicate: Ctrl/Cmd + D
      if (modKey && e.key === 'd' && selectedComponentId) {
        e.preventDefault();
        duplicateComponent(selectedComponentId);
        logger.log('Duplicate triggered');
        return;
      }

      // Copy: Ctrl/Cmd + C
      if (modKey && e.key === 'c' && selectedComponent) {
        e.preventDefault();
        copyToClipboard(selectedComponent);
        logger.log('Copy triggered');
        return;
      }

      // Paste: Ctrl/Cmd + V
      if (modKey && e.key === 'v' && clipboard && selectedComponentId) {
        e.preventDefault();
        pasteFromClipboard(selectedComponentId, 'after');
        logger.log('Paste triggered');
        return;
      }

      // Escape: 선택 해제
      if (e.key === 'Escape') {
        const selectComponent = useEditorState.getState().selectComponent;
        selectComponent(null);
        return;
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [
    config.keyboardShortcuts,
    isEditorActive,
    canUndo,
    canRedo,
    hasChanges,
    selectedComponentId,
    selectedComponent,
    clipboard,
    undo,
    redo,
    saveLayout,
    deleteComponent,
    duplicateComponent,
    copyToClipboard,
    pasteFromClipboard,
  ]);

  // 자동 저장
  useEffect(() => {
    if (!config.autoSave || !isEditorActive || !hasChanges) return;

    const timer = setTimeout(() => {
      saveLayout('Auto-save').catch((err) => {
        logger.error('Auto-save failed:', err);
      });
    }, config.autoSaveInterval);

    return () => {
      clearTimeout(timer);
    };
  }, [config.autoSave, config.autoSaveInterval, isEditorActive, hasChanges, saveLayout]);

  // 변경 사항이 있을 때 페이지 이탈 경고
  useEffect(() => {
    if (!hasChanges) return;

    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, [hasChanges]);

  // 컨텍스트 값
  const contextValue = useMemo<EditorContextValue>(
    () => ({
      isEditorActive,
      config,
      initialize,
      deactivate,
      selectedComponent,
      layoutData,
      canUndo,
      canRedo,
      hasChanges,
      isLoading,
      isSaving,
      error,
    }),
    [
      isEditorActive,
      config,
      initialize,
      deactivate,
      selectedComponent,
      layoutData,
      canUndo,
      canRedo,
      hasChanges,
      isLoading,
      isSaving,
      error,
    ]
  );

  return (
    <EditorContext.Provider value={contextValue}>
      {children}
    </EditorContext.Provider>
  );
}

// ============================================================================
// Hook
// ============================================================================

/**
 * 편집기 컨텍스트 사용 훅
 *
 * @throws EditorProvider 외부에서 사용 시 에러
 */
export function useEditorContext(): EditorContextValue {
  const context = useContext(EditorContext);

  if (!context) {
    throw new Error('useEditorContext must be used within an EditorProvider');
  }

  return context;
}

/**
 * 편집기 컨텍스트 사용 훅 (옵션)
 *
 * EditorProvider 외부에서도 사용 가능 (null 반환)
 */
export function useEditorContextOptional(): EditorContextValue | null {
  return useContext(EditorContext);
}

// ============================================================================
// Export
// ============================================================================

export { EditorContext };
export default EditorProvider;
