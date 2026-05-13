/**
 * EditorOverlay.tsx
 *
 * G7 위지윅 레이아웃 편집기의 오버레이 컴포넌트
 *
 * 역할:
 * - 편집기 UI를 실제 화면 위에 오버레이로 표시
 * - 컴포넌트 선택 및 호버 하이라이트
 * - 컴포넌트 액션 버튼 (복사, 삭제, 이동 등)
 * - 드래그앤드롭 핸들링
 */

import React, { useCallback, useEffect, useRef, useState, useMemo } from 'react';
import { useEditorState } from '../hooks/useEditorState';
import type { ComponentDefinition, DropPosition } from '../types/editor';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('EditorOverlay');

// ============================================================================
// 타입 정의
// ============================================================================

export interface EditorOverlayProps {
  /** 오버레이 대상 컨테이너 */
  containerRef: React.RefObject<HTMLElement | null>;

  /** 활성화 여부 */
  enabled?: boolean;

  /** 선택 모드 (클릭 시 선택) */
  selectionMode?: boolean;

  /** 편집 모드 (직접 편집 가능) */
  editMode?: boolean;
}

interface OverlayRect {
  id: string;
  rect: DOMRect;
  component: ComponentDefinition;
  isSelected: boolean;
  isHovered: boolean;
}

interface ActionButtonsProps {
  rect: DOMRect;
  componentId: string;
  onCopy: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
  onMoveUp: () => void;
  onMoveDown: () => void;
}

// ============================================================================
// 액션 버튼 컴포넌트
// ============================================================================

function ActionButtons({
  rect,
  componentId,
  onCopy,
  onDuplicate,
  onDelete,
  onMoveUp,
  onMoveDown,
}: ActionButtonsProps) {
  // 버튼 위치 계산 (컴포넌트 우상단)
  const top = Math.max(0, rect.top - 36);
  const right = window.innerWidth - rect.right;

  return (
    <div
      className="fixed z-50 flex items-center gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg px-1 py-1"
      style={{ top, right: Math.max(8, right) }}
      onClick={(e) => e.stopPropagation()}
    >
      {/* 이동 버튼 */}
      <button
        className="p-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
        onClick={onMoveUp}
        title="위로 이동"
      >
        ⬆️
      </button>
      <button
        className="p-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
        onClick={onMoveDown}
        title="아래로 이동"
      >
        ⬇️
      </button>

      <div className="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1" />

      {/* 복사/복제 버튼 */}
      <button
        className="p-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
        onClick={onCopy}
        title="복사"
      >
        📋
      </button>
      <button
        className="p-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
        onClick={onDuplicate}
        title="복제"
      >
        📑
      </button>

      <div className="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1" />

      {/* 삭제 버튼 */}
      <button
        className="p-1.5 text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 hover:bg-red-50 dark:hover:bg-red-900 rounded"
        onClick={onDelete}
        title="삭제"
      >
        🗑️
      </button>

      {/* 컴포넌트 ID 표시 */}
      <div className="ml-2 px-2 py-0.5 text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 rounded">
        {componentId}
      </div>
    </div>
  );
}

// ============================================================================
// 드롭 인디케이터 컴포넌트
// ============================================================================

interface DropIndicatorProps {
  rect: DOMRect;
  position: DropPosition;
}

function DropIndicator({ rect, position }: DropIndicatorProps) {
  const style: React.CSSProperties = {
    position: 'fixed',
    pointerEvents: 'none',
    zIndex: 40,
  };

  if (position === 'before') {
    return (
      <div
        style={{
          ...style,
          left: rect.left,
          top: rect.top - 2,
          width: rect.width,
          height: 4,
        }}
        className="bg-blue-500"
      />
    );
  }

  if (position === 'after') {
    return (
      <div
        style={{
          ...style,
          left: rect.left,
          top: rect.bottom - 2,
          width: rect.width,
          height: 4,
        }}
        className="bg-blue-500"
      />
    );
  }

  if (position === 'inside') {
    return (
      <div
        style={{
          ...style,
          left: rect.left,
          top: rect.top,
          width: rect.width,
          height: rect.height,
        }}
        className="border-2 border-dashed border-blue-500 bg-blue-500 bg-opacity-10"
      />
    );
  }

  return null;
}

// ============================================================================
// 하이라이트 박스 컴포넌트
// ============================================================================

interface HighlightBoxProps {
  rect: DOMRect;
  containerRect: DOMRect | null;
  type: 'selected' | 'hovered';
  label?: string;
}

function HighlightBox({ rect, containerRect, type, label }: HighlightBoxProps) {
  const isSelected = type === 'selected';

  // 컨테이너 기준 상대 좌표 계산
  const offsetX = containerRect ? containerRect.left : 0;
  const offsetY = containerRect ? containerRect.top : 0;

  return (
    <div
      className={`absolute pointer-events-none ${
        isSelected
          ? 'border-2 border-blue-500'
          : 'border border-dashed border-green-500'
      }`}
      style={{
        left: rect.left - offsetX,
        top: rect.top - offsetY,
        width: rect.width,
        height: rect.height,
        zIndex: isSelected ? 31 : 30,
      }}
    >
      {/* 모서리 핸들 (선택된 경우만) */}
      {isSelected && (
        <>
          <div className="absolute -left-1 -top-1 w-2 h-2 bg-blue-500 rounded-full" />
          <div className="absolute -right-1 -top-1 w-2 h-2 bg-blue-500 rounded-full" />
          <div className="absolute -left-1 -bottom-1 w-2 h-2 bg-blue-500 rounded-full" />
          <div className="absolute -right-1 -bottom-1 w-2 h-2 bg-blue-500 rounded-full" />
        </>
      )}

      {/* 레이블 */}
      {label && (
        <div
          className={`absolute -top-6 left-0 px-1.5 py-0.5 text-xs text-white rounded whitespace-nowrap ${
            isSelected ? 'bg-blue-500' : 'bg-green-500'
          }`}
        >
          {label}
        </div>
      )}
    </div>
  );
}

// ============================================================================
// EditorOverlay 메인 컴포넌트
// ============================================================================

export function EditorOverlay({
  containerRef,
  enabled = true,
  selectionMode = true,
}: EditorOverlayProps) {
  const overlayRef = useRef<HTMLDivElement>(null);

  // Zustand 상태
  const layoutData = useEditorState((state) => state.layoutData);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const hoveredComponentId = useEditorState((state) => state.hoveredComponentId);
  const selectComponent = useEditorState((state) => state.selectComponent);
  const setHoveredComponentId = useEditorState((state) => state.setHoveredComponentId);
  const deleteComponent = useEditorState((state) => state.deleteComponent);
  const duplicateComponent = useEditorState((state) => state.duplicateComponent);
  const copyToClipboard = useEditorState((state) => state.copyToClipboard);
  const moveComponent = useEditorState((state) => state.moveComponent);

  // 로컬 상태
  const [overlayRects, setOverlayRects] = useState<OverlayRect[]>([]);
  const [containerRect, setContainerRect] = useState<DOMRect | null>(null);
  const [dragState, setDragState] = useState<{
    isDragging: boolean;
    targetId: string | null;
    position: DropPosition | null;
    targetRect: DOMRect | null;
  }>({
    isDragging: false,
    targetId: null,
    position: null,
    targetRect: null,
  });

  // 컴포넌트 요소 찾기 유틸
  const findComponentElements = useCallback(() => {
    if (!containerRef.current || !layoutData) return [];

    // DynamicRenderer가 편집 모드에서 data-editor-id 속성을 주입함
    const elements = containerRef.current.querySelectorAll('[data-editor-id]');
    const rects: OverlayRect[] = [];

    // 컴포넌트 찾기 헬퍼
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

    elements.forEach((el) => {
      const id = el.getAttribute('data-editor-id');
      if (!id) return;

      const rect = el.getBoundingClientRect();
      const component = findComponent(layoutData.components, id);

      if (component) {
        rects.push({
          id,
          rect,
          component,
          isSelected: id === selectedComponentId,
          isHovered: id === hoveredComponentId,
        });
      }
    });

    return rects;
  }, [containerRef, layoutData, selectedComponentId, hoveredComponentId]);

  // 오버레이 위치 업데이트
  const updateOverlayRects = useCallback(() => {
    setOverlayRects(findComponentElements());
    if (containerRef.current) {
      setContainerRect(containerRef.current.getBoundingClientRect());
    }
  }, [findComponentElements, containerRef]);

  // 위치 업데이트 효과
  useEffect(() => {
    if (!enabled) return;

    updateOverlayRects();

    // 리사이즈 및 스크롤 시 업데이트
    const handleUpdate = () => {
      requestAnimationFrame(updateOverlayRects);
    };

    window.addEventListener('resize', handleUpdate);
    window.addEventListener('scroll', handleUpdate, true);

    // ResizeObserver
    const resizeObserver = new ResizeObserver(handleUpdate);
    if (containerRef.current) {
      resizeObserver.observe(containerRef.current);
    }

    // MutationObserver (DOM 변경 감지)
    const mutationObserver = new MutationObserver(handleUpdate);
    if (containerRef.current) {
      mutationObserver.observe(containerRef.current, {
        childList: true,
        subtree: true,
        attributes: true,
      });
    }

    return () => {
      window.removeEventListener('resize', handleUpdate);
      window.removeEventListener('scroll', handleUpdate, true);
      resizeObserver.disconnect();
      mutationObserver.disconnect();
    };
  }, [enabled, containerRef, updateOverlayRects]);

  // 클릭 핸들러 (가장 안쪽 컴포넌트 우선 선택)
  const handleClick = useCallback(
    (e: React.MouseEvent) => {
      if (!selectionMode) return;

      const target = e.target as HTMLElement;

      // 오버레이 자체 클릭은 무시
      if (overlayRef.current?.contains(target)) return;

      // 컴포넌트 요소 찾기 (target 자체가 data-editor-id를 가지면 그것 사용)
      let componentEl: Element | null = null;
      if (target.hasAttribute('data-editor-id')) {
        componentEl = target;
      } else {
        componentEl = target.closest('[data-editor-id]');
      }

      if (componentEl) {
        const componentId = componentEl.getAttribute('data-editor-id');
        if (componentId) {
          selectComponent(componentId);
          e.preventDefault();
          e.stopPropagation();
        }
      } else {
        // 빈 영역 클릭 시 선택 해제
        selectComponent(null);
      }
    },
    [selectionMode, selectComponent]
  );

  // 마우스 이동 핸들러 (가장 안쪽 컴포넌트 우선)
  const handleMouseMove = useCallback(
    (e: React.MouseEvent) => {
      const target = e.target as HTMLElement;

      // 오버레이 자체 위는 무시
      if (overlayRef.current?.contains(target)) return;

      // 컴포넌트 요소 찾기 (target 자체가 data-editor-id를 가지면 그것 사용)
      let componentEl: Element | null = null;
      if (target.hasAttribute('data-editor-id')) {
        componentEl = target;
      } else {
        componentEl = target.closest('[data-editor-id]');
      }

      if (componentEl) {
        const componentId = componentEl.getAttribute('data-editor-id');
        if (componentId && componentId !== hoveredComponentId) {
          setHoveredComponentId(componentId);
        }
      } else if (hoveredComponentId) {
        setHoveredComponentId(null);
      }
    },
    [hoveredComponentId, setHoveredComponentId]
  );

  // 마우스 이탈 핸들러
  const handleMouseLeave = useCallback(() => {
    setHoveredComponentId(null);
  }, [setHoveredComponentId]);

  // 액션 핸들러들
  const handleCopy = useCallback(() => {
    if (!selectedComponentId) return;
    const overlay = overlayRects.find((o) => o.id === selectedComponentId);
    if (overlay) {
      copyToClipboard(overlay.component);
      logger.log('Copied:', selectedComponentId);
    }
  }, [selectedComponentId, overlayRects, copyToClipboard]);

  const handleDuplicate = useCallback(() => {
    if (!selectedComponentId) return;
    duplicateComponent(selectedComponentId);
    logger.log('Duplicated:', selectedComponentId);
  }, [selectedComponentId, duplicateComponent]);

  const handleDelete = useCallback(() => {
    if (!selectedComponentId) return;
    deleteComponent(selectedComponentId);
    logger.log('Deleted:', selectedComponentId);
  }, [selectedComponentId, deleteComponent]);

  const handleMoveUp = useCallback(() => {
    if (!selectedComponentId) return;
    // TODO: 형제 요소 중 이전 요소 찾아서 이동
    logger.log('Move up:', selectedComponentId);
  }, [selectedComponentId]);

  const handleMoveDown = useCallback(() => {
    if (!selectedComponentId) return;
    // TODO: 형제 요소 중 다음 요소 찾아서 이동
    logger.log('Move down:', selectedComponentId);
  }, [selectedComponentId]);

  // 선택된 컴포넌트 정보
  const selectedOverlay = useMemo(
    () => overlayRects.find((o) => o.isSelected),
    [overlayRects]
  );

  const hoveredOverlay = useMemo(
    () => overlayRects.find((o) => o.isHovered && !o.isSelected),
    [overlayRects]
  );

  if (!enabled) return null;

  return (
    <>
      {/* 오버레이 컨테이너 - 캔버스 영역 내부에만 적용 */}
      <div ref={overlayRef} className="absolute inset-0 pointer-events-none z-20">
        {/* 호버 하이라이트 */}
        {hoveredOverlay && (
          <HighlightBox
            rect={hoveredOverlay.rect}
            containerRect={containerRect}
            type="hovered"
            label={hoveredOverlay.component.name}
          />
        )}

        {/* 선택 하이라이트 */}
        {selectedOverlay && (
          <>
            <HighlightBox
              rect={selectedOverlay.rect}
              containerRect={containerRect}
              type="selected"
              label={selectedOverlay.component.name}
            />

            {/* 액션 버튼 */}
            <div className="pointer-events-auto">
              <ActionButtons
                rect={selectedOverlay.rect}
                componentId={selectedOverlay.id}
                onCopy={handleCopy}
                onDuplicate={handleDuplicate}
                onDelete={handleDelete}
                onMoveUp={handleMoveUp}
                onMoveDown={handleMoveDown}
              />
            </div>
          </>
        )}

        {/* 드롭 인디케이터 */}
        {dragState.isDragging && dragState.targetRect && dragState.position && (
          <DropIndicator
            rect={dragState.targetRect}
            position={dragState.position}
          />
        )}
      </div>
    </>
  );
}

// ============================================================================
// Export
// ============================================================================

export default EditorOverlay;
