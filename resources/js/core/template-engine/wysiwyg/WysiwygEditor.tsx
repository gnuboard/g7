/**
 * WysiwygEditor.tsx
 *
 * 그누보드7 위지윅 레이아웃 편집기의 메인 컴포넌트
 *
 * 역할:
 * - 편집기 전체 레이아웃 구성
 * - 서브 컴포넌트 통합 (Toolbar, LayoutTree, PreviewCanvas, PropertyPanel)
 * - 키보드 단축키 처리
 * - 편집 모드 초기화 및 관리
 */

import React, { useEffect, useCallback, useRef, useState } from 'react';
import { EditorProvider } from './EditorContext';
import { useEditorState } from './hooks/useEditorState';
import { useHistory } from './hooks/useHistory';
import { Toolbar } from './components/Toolbar';
import { LayoutTree } from './components/LayoutTree';
import { PreviewCanvas } from './components/PreviewCanvas';
import { PropertyPanel } from './components/PropertyPanel';
import { EditorOverlay } from './components/EditorOverlay';
import type { LayoutData, EditMode } from './types/editor';
import { createLogger } from '../../utils/Logger';

const logger = createLogger('WysiwygEditor');

// ============================================================================
// 타입 정의
// ============================================================================

export interface WysiwygEditorProps {
  /** 편집할 레이아웃 데이터 */
  layoutData: LayoutData;

  /** 템플릿 ID */
  templateId: string;

  /** 닫기 핸들러 */
  onClose?: () => void;

  /** 저장 완료 핸들러 */
  onSaveComplete?: (layoutData: LayoutData) => void;

  /** 발행 완료 핸들러 */
  onPublishComplete?: (layoutData: LayoutData) => void;

  /** 초기 편집 모드 */
  initialEditMode?: EditMode;

  /** 읽기 전용 모드 */
  readOnly?: boolean;

  /** 클래스명 */
  className?: string;
}

// ============================================================================
// 패널 크기 관리
// ============================================================================

interface PanelSizes {
  leftPanel: number;
  rightPanel: number;
  bottomPanel: number;
}

const DEFAULT_PANEL_SIZES: PanelSizes = {
  leftPanel: 280,
  rightPanel: 320,
  bottomPanel: 200,
};

const MIN_PANEL_SIZE = 200;
const MAX_LEFT_PANEL_SIZE = 400;
const MAX_RIGHT_PANEL_SIZE = 500;
const MAX_BOTTOM_PANEL_SIZE = 400;

// ============================================================================
// 패널 리사이저 컴포넌트
// ============================================================================

interface PanelResizerProps {
  direction: 'horizontal' | 'vertical';
  onResize: (delta: number) => void;
}

function PanelResizer({ direction, onResize }: PanelResizerProps) {
  const [isDragging, setIsDragging] = useState(false);
  const startPosRef = useRef(0);

  const handleMouseDown = useCallback(
    (e: React.MouseEvent) => {
      e.preventDefault();
      setIsDragging(true);
      startPosRef.current = direction === 'horizontal' ? e.clientX : e.clientY;

      const handleMouseMove = (moveEvent: MouseEvent) => {
        const currentPos =
          direction === 'horizontal' ? moveEvent.clientX : moveEvent.clientY;
        const delta = currentPos - startPosRef.current;
        startPosRef.current = currentPos;
        onResize(delta);
      };

      const handleMouseUp = () => {
        setIsDragging(false);
        document.removeEventListener('mousemove', handleMouseMove);
        document.removeEventListener('mouseup', handleMouseUp);
      };

      document.addEventListener('mousemove', handleMouseMove);
      document.addEventListener('mouseup', handleMouseUp);
    },
    [direction, onResize]
  );

  const isHorizontal = direction === 'horizontal';

  return (
    <div
      className={`${
        isHorizontal ? 'w-1 cursor-col-resize' : 'h-1 cursor-row-resize'
      } bg-gray-200 dark:bg-gray-700 hover:bg-blue-400 dark:hover:bg-blue-600 transition-colors ${
        isDragging ? 'bg-blue-500 dark:bg-blue-500' : ''
      }`}
      onMouseDown={handleMouseDown}
    />
  );
}

// ============================================================================
// 에디터 내부 컴포넌트 (Provider 내부)
// ============================================================================

interface EditorInnerProps {
  onClose?: () => void;
  onSaveComplete?: (layoutData: LayoutData) => void;
  readOnly?: boolean;
  className?: string;
}

function EditorInner({
  onClose,
  onSaveComplete,
  readOnly = false,
  className = '',
}: EditorInnerProps) {
  // 상태
  const layoutData = useEditorState((state) => state.layoutData);
  const editMode = useEditorState((state) => state.editMode);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const saveLayout = useEditorState((state) => state.saveLayout);
  const selectComponent = useEditorState((state) => state.selectComponent);
  const deleteComponent = useEditorState((state) => state.deleteComponent);
  const duplicateComponent = useEditorState((state) => state.duplicateComponent);

  // History
  const { undo, redo, canUndo, canRedo } = useHistory();

  // 패널 크기
  const [panelSizes, setPanelSizes] = useState<PanelSizes>(DEFAULT_PANEL_SIZES);
  const [showLeftPanel, setShowLeftPanel] = useState(true);
  const [showRightPanel, setShowRightPanel] = useState(true);
  const [showBottomPanel, setShowBottomPanel] = useState(true);

  // 미리보기 캔버스 ref
  const canvasRef = useRef<HTMLDivElement>(null);

  // 키보드 단축키 처리
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // 입력 필드에서는 단축키 비활성화
      if (
        e.target instanceof HTMLInputElement ||
        e.target instanceof HTMLTextAreaElement ||
        (e.target as HTMLElement).contentEditable === 'true'
      ) {
        return;
      }

      const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
      const modKey = isMac ? e.metaKey : e.ctrlKey;

      // Ctrl/Cmd + Z: Undo
      if (modKey && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        if (canUndo) {
          undo();
          logger.log('Keyboard shortcut: Undo');
        }
        return;
      }

      // Ctrl/Cmd + Shift + Z: Redo
      if (modKey && e.key === 'z' && e.shiftKey) {
        e.preventDefault();
        if (canRedo) {
          redo();
          logger.log('Keyboard shortcut: Redo');
        }
        return;
      }

      // Ctrl/Cmd + Y: Redo (Windows style)
      if (modKey && e.key === 'y') {
        e.preventDefault();
        if (canRedo) {
          redo();
          logger.log('Keyboard shortcut: Redo (Y)');
        }
        return;
      }

      // Ctrl/Cmd + S: Save
      if (modKey && e.key === 's') {
        e.preventDefault();
        if (!readOnly) {
          handleSave();
          logger.log('Keyboard shortcut: Save');
        }
        return;
      }

      // Delete/Backspace: Delete selected component
      if ((e.key === 'Delete' || e.key === 'Backspace') && selectedComponentId) {
        e.preventDefault();
        if (!readOnly) {
          deleteComponent(selectedComponentId);
          logger.log('Keyboard shortcut: Delete');
        }
        return;
      }

      // Ctrl/Cmd + D: Duplicate
      if (modKey && e.key === 'd' && selectedComponentId) {
        e.preventDefault();
        if (!readOnly) {
          duplicateComponent(selectedComponentId);
          logger.log('Keyboard shortcut: Duplicate');
        }
        return;
      }

      // Escape: Deselect
      if (e.key === 'Escape') {
        e.preventDefault();
        selectComponent(null);
        logger.log('Keyboard shortcut: Deselect');
        return;
      }

      // Ctrl/Cmd + 1: Toggle left panel
      if (modKey && e.key === '1') {
        e.preventDefault();
        setShowLeftPanel((prev) => !prev);
        return;
      }

      // Ctrl/Cmd + 2: Toggle right panel
      if (modKey && e.key === '2') {
        e.preventDefault();
        setShowRightPanel((prev) => !prev);
        return;
      }

      // Ctrl/Cmd + 3: Toggle bottom panel
      if (modKey && e.key === '3') {
        e.preventDefault();
        setShowBottomPanel((prev) => !prev);
        return;
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [
    canUndo,
    canRedo,
    undo,
    redo,
    selectedComponentId,
    selectComponent,
    deleteComponent,
    duplicateComponent,
    readOnly,
  ]);

  // 저장 핸들러
  const handleSave = useCallback(async () => {
    try {
      await saveLayout('Manual save');
      if (layoutData && onSaveComplete) {
        onSaveComplete(layoutData);
      }
      logger.log('Layout saved successfully');
    } catch (error) {
      logger.error('Failed to save layout:', error);
    }
  }, [saveLayout, layoutData, onSaveComplete]);

  // 패널 리사이즈 핸들러
  const handleLeftPanelResize = useCallback((delta: number) => {
    setPanelSizes((prev) => ({
      ...prev,
      leftPanel: Math.min(
        MAX_LEFT_PANEL_SIZE,
        Math.max(MIN_PANEL_SIZE, prev.leftPanel + delta)
      ),
    }));
  }, []);

  const handleRightPanelResize = useCallback((delta: number) => {
    setPanelSizes((prev) => ({
      ...prev,
      rightPanel: Math.min(
        MAX_RIGHT_PANEL_SIZE,
        Math.max(MIN_PANEL_SIZE, prev.rightPanel - delta)
      ),
    }));
  }, []);

  const handleBottomPanelResize = useCallback((delta: number) => {
    setPanelSizes((prev) => ({
      ...prev,
      bottomPanel: Math.min(
        MAX_BOTTOM_PANEL_SIZE,
        Math.max(MIN_PANEL_SIZE, prev.bottomPanel - delta)
      ),
    }));
  }, []);

  // 에디트 모드에 따른 메인 콘텐츠 렌더링
  const renderMainContent = () => {
    switch (editMode) {
      case 'visual':
        return (
          <div ref={canvasRef} className="relative flex-1 overflow-hidden">
            <PreviewCanvas showOverlay={!readOnly} />
            {!readOnly && <EditorOverlay containerRef={canvasRef} />}
          </div>
        );

      case 'json':
        return (
          <div className="flex-1 overflow-hidden bg-gray-900 p-4">
            <pre className="h-full overflow-auto text-sm text-green-400 font-mono">
              {JSON.stringify(layoutData, null, 2)}
            </pre>
          </div>
        );

      case 'split':
        return (
          <div className="flex-1 flex overflow-hidden">
            {/* Visual 영역 */}
            <div ref={canvasRef} className="relative flex-1 overflow-hidden">
              <PreviewCanvas showOverlay={!readOnly} />
              {!readOnly && <EditorOverlay containerRef={canvasRef} />}
            </div>

            <PanelResizer direction="horizontal" onResize={() => {}} />

            {/* JSON 영역 */}
            <div className="flex-1 overflow-hidden bg-gray-900 p-4">
              <pre className="h-full overflow-auto text-sm text-green-400 font-mono">
                {JSON.stringify(layoutData, null, 2)}
              </pre>
            </div>
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div
      className={`flex flex-col h-screen bg-gray-100 dark:bg-gray-900 ${className}`}
    >
      {/* 툴바 */}
      <Toolbar onClose={onClose} onSaveComplete={handleSave} />

      {/* 메인 콘텐츠 영역 */}
      <div className="flex-1 flex overflow-hidden">
        {/* 좌측 패널: 레이아웃 트리 */}
        {showLeftPanel && (
          <>
            <div
              className="flex-shrink-0 overflow-hidden bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700"
              style={{ width: panelSizes.leftPanel }}
            >
              <div className="h-full flex flex-col">
                <div className="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                  <h2 className="text-sm font-semibold text-gray-900 dark:text-white">
                    레이아웃 트리
                  </h2>
                  <button
                    className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    onClick={() => setShowLeftPanel(false)}
                    title="패널 닫기"
                  >
                    ✕
                  </button>
                </div>
                <div className="flex-1 overflow-auto">
                  <LayoutTree enableDragDrop={!readOnly} />
                </div>
              </div>
            </div>
            <PanelResizer direction="horizontal" onResize={handleLeftPanelResize} />
          </>
        )}

        {/* 중앙: 미리보기/편집 영역 */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {renderMainContent()}

          {/* 하단 패널: 데이터소스/상태 (Phase 3) */}
          {showBottomPanel && (
            <>
              <PanelResizer
                direction="vertical"
                onResize={handleBottomPanelResize}
              />
              <div
                className="flex-shrink-0 overflow-hidden bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700"
                style={{ height: panelSizes.bottomPanel }}
              >
                <div className="h-full flex flex-col">
                  <div className="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-white">
                      데이터소스 / 상태
                    </h2>
                    <button
                      className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                      onClick={() => setShowBottomPanel(false)}
                      title="패널 닫기"
                    >
                      ✕
                    </button>
                  </div>
                  <div className="flex-1 overflow-auto p-4">
                    <p className="text-sm text-gray-500 dark:text-gray-400 text-center">
                      데이터소스 관리자 (Phase 3에서 구현)
                    </p>
                  </div>
                </div>
              </div>
            </>
          )}
        </div>

        {/* 우측 패널: 속성 패널 */}
        {showRightPanel && (
          <>
            <PanelResizer direction="horizontal" onResize={handleRightPanelResize} />
            <div
              className="flex-shrink-0 overflow-hidden bg-white dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700"
              style={{ width: panelSizes.rightPanel }}
            >
              <div className="h-full flex flex-col">
                <div className="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                  <h2 className="text-sm font-semibold text-gray-900 dark:text-white">
                    속성
                  </h2>
                  <button
                    className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    onClick={() => setShowRightPanel(false)}
                    title="패널 닫기"
                  >
                    ✕
                  </button>
                </div>
                <div className="flex-1 overflow-auto">
                  <PropertyPanel />
                </div>
              </div>
            </div>
          </>
        )}
      </div>

      {/* 패널 토글 버튼 (패널이 숨겨진 경우) */}
      {(!showLeftPanel || !showRightPanel || !showBottomPanel) && (
        <div className="fixed bottom-4 left-1/2 transform -translate-x-1/2 flex items-center gap-2 bg-white dark:bg-gray-800 rounded-lg shadow-lg px-4 py-2 border border-gray-200 dark:border-gray-700">
          {!showLeftPanel && (
            <button
              className="px-3 py-1 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              onClick={() => setShowLeftPanel(true)}
            >
              📑 트리
            </button>
          )}
          {!showBottomPanel && (
            <button
              className="px-3 py-1 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              onClick={() => setShowBottomPanel(true)}
            >
              📊 데이터
            </button>
          )}
          {!showRightPanel && (
            <button
              className="px-3 py-1 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              onClick={() => setShowRightPanel(true)}
            >
              ⚙️ 속성
            </button>
          )}
        </div>
      )}

      {/* 읽기 전용 배지 */}
      {readOnly && (
        <div className="fixed top-16 right-4 bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 px-3 py-1 rounded-full text-sm font-medium shadow">
          읽기 전용
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 메인 WysiwygEditor 컴포넌트
// ============================================================================

export function WysiwygEditor({
  layoutData,
  templateId,
  onClose,
  onSaveComplete,
  onPublishComplete,
  initialEditMode = 'visual',
  readOnly = false,
  className = '',
}: WysiwygEditorProps) {
  return (
    <EditorProvider
      layoutData={layoutData}
      templateId={templateId}
      initialEditMode={initialEditMode}
    >
      <EditorInner
        onClose={onClose}
        onSaveComplete={onSaveComplete}
        readOnly={readOnly}
        className={className}
      />
    </EditorProvider>
  );
}

// ============================================================================
// Export
// ============================================================================

export default WysiwygEditor;
