/**
 * LayoutTree.tsx
 *
 * G7 위지윅 레이아웃 편집기의 레이아웃 트리 컴포넌트
 *
 * 역할:
 * - 컴포넌트 계층 구조 시각화
 * - 드래그앤드롭 정렬
 * - 조건/반복 렌더링 표시
 * - 확장 컴포넌트 출처 표시
 */

import React, {
  useCallback,
  useMemo,
  useState,
  type MouseEvent,
  type DragEvent,
} from 'react';
import { useEditorState } from '../hooks/useEditorState';
import type {
  ComponentDefinition,
  LayoutTreeNodeInfo,
  DropPosition,
} from '../types/editor';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('LayoutTree');

// ============================================================================
// 타입 정의
// ============================================================================

export interface LayoutTreeProps {
  /** 클래스명 */
  className?: string;

  /** 컨텍스트 메뉴 표시 여부 */
  showContextMenu?: boolean;

  /** 드래그앤드롭 활성화 여부 */
  enableDragDrop?: boolean;

  /** 노드 클릭 핸들러 */
  onNodeClick?: (id: string) => void;

  /** 노드 더블클릭 핸들러 */
  onNodeDoubleClick?: (id: string) => void;
}

interface TreeNodeProps {
  /** 컴포넌트 정의 */
  component: ComponentDefinition;

  /** 노드 깊이 */
  depth: number;

  /** 트리 경로 (고유 ID 생성용) */
  treePath: string;

  /** 드래그앤드롭 활성화 여부 */
  enableDragDrop: boolean;

  /** 확장/접힘 상태 */
  expandedNodes: Set<string>;

  /** 확장/접힘 토글 */
  onToggleExpand: (id: string) => void;

  /** 노드 클릭 핸들러 */
  onNodeClick?: (id: string) => void;

  /** 노드 더블클릭 핸들러 */
  onNodeDoubleClick?: (id: string) => void;

  /** 드래그 시작 핸들러 */
  onDragStart: (e: DragEvent, id: string) => void;

  /** 드래그 오버 핸들러 */
  onDragOver: (e: DragEvent, id: string) => void;

  /** 드롭 핸들러 */
  onDrop: (e: DragEvent, id: string, position: DropPosition) => void;

  /** 드래그 엔드 핸들러 */
  onDragEnd: () => void;

  /** 현재 드래그 중인 노드 ID */
  draggedNodeId: string | null;

  /** 드롭 타겟 노드 ID */
  dropTargetId: string | null;

  /** 드롭 위치 */
  dropPosition: DropPosition | null;
}

interface ContextMenuState {
  visible: boolean;
  x: number;
  y: number;
  nodeId: string | null;
}

// ============================================================================
// 헬퍼 함수
// ============================================================================

/**
 * LayoutTree 경로 형식을 DynamicRenderer 경로 형식으로 변환
 * 예: "2.4.0.0" -> "2.children.4.children.0.children.0"
 */
function convertTreePathToDynamicPath(treePath: string): string {
  const parts = treePath.split('.');
  if (parts.length === 1) return parts[0];
  // 첫 번째 인덱스 후 나머지는 .children.인덱스 형식으로 연결
  return parts[0] + parts.slice(1).map((p) => `.children.${p}`).join('');
}

/**
 * 컴포넌트 ID로 트리 경로를 찾아 반환
 * @returns 경로에 있는 모든 노드의 ID 배열 (펼쳐야 할 노드들)
 */
function findPathToComponent(
  components: (ComponentDefinition | string)[],
  targetId: string,
  currentPath: string = ''
): string[] | null {
  for (let i = 0; i < components.length; i++) {
    const comp = components[i];
    if (typeof comp === 'string') continue;

    const nodePath = currentPath ? `${currentPath}.${i}` : `${i}`;
    const nodeId = comp.id || `tree_${nodePath}`;

    if (nodeId === targetId || comp.id === targetId) {
      // 찾음 - 현재 노드까지의 경로 반환
      return [nodeId];
    }

    if (comp.children && comp.children.length > 0) {
      const childPath = findPathToComponent(comp.children, targetId, nodePath);
      if (childPath) {
        // 자식에서 찾음 - 현재 노드를 경로에 추가
        return [nodeId, ...childPath];
      }
    }
  }
  return null;
}

/**
 * 컴포넌트에서 노드 정보 추출
 */
function extractNodeInfo(component: ComponentDefinition, depth: number): LayoutTreeNodeInfo {
  const extensionSource = component.props?._extensionSource as string | undefined;
  const childCount = component.children
    ? component.children.filter((c) => typeof c !== 'string').length
    : 0;

  return {
    id: component.id,
    name: component.name,
    type: component.type === 'extension_point' ? 'extension_point' : component.type,
    hasCondition: !!component.if,
    hasIteration: !!component.iteration,
    isFromExtension: !!extensionSource,
    extensionSource,
    childCount,
    depth,
  };
}

/**
 * 노드 타입에 따른 아이콘 반환
 */
function getNodeIcon(type: string): string {
  switch (type) {
    case 'basic':
      return '📦';
    case 'composite':
      return '🧩';
    case 'layout':
      return '📐';
    case 'extension_point':
      return '🔌';
    default:
      return '📄';
  }
}

/**
 * 노드 타입에 따른 색상 클래스 반환
 */
function getNodeColorClass(nodeInfo: LayoutTreeNodeInfo): string {
  if (nodeInfo.isFromExtension) {
    return 'text-purple-600 dark:text-purple-400';
  }

  switch (nodeInfo.type) {
    case 'basic':
      return 'text-blue-600 dark:text-blue-400';
    case 'composite':
      return 'text-green-600 dark:text-green-400';
    case 'layout':
      return 'text-orange-600 dark:text-orange-400';
    case 'extension_point':
      return 'text-pink-600 dark:text-pink-400';
    default:
      return 'text-gray-600 dark:text-gray-400';
  }
}

// ============================================================================
// TreeNode 컴포넌트 (React.memo로 최적화)
// ============================================================================

const TreeNode = React.memo(function TreeNode({
  component,
  depth,
  treePath,
  enableDragDrop,
  expandedNodes,
  onToggleExpand,
  onNodeClick,
  onNodeDoubleClick,
  onDragStart,
  onDragOver,
  onDrop,
  onDragEnd,
  draggedNodeId,
  dropTargetId,
  dropPosition,
}: TreeNodeProps) {
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const hoveredComponentId = useEditorState((state) => state.hoveredComponentId);
  const setHoveredComponentId = useEditorState((state) => state.setHoveredComponentId);

  // component.id가 없는 경우 treePath를 기반으로 고유 ID 생성
  const componentId = component.id || `tree_${treePath}`;

  const nodeInfo = useMemo(() => extractNodeInfo(component, depth), [component, depth]);
  const hasChildren = component.children && component.children.length > 0;
  const isExpanded = expandedNodes.has(componentId);

  // isSelected 판정
  // Note: selectComponent에서 auto_path: ID가 영구 ID로 정규화되므로
  // 여기서는 단순히 ID 비교만 하면 됨
  const isSelected = useMemo(() => {
    if (!selectedComponentId) return false;
    if (selectedComponentId === componentId) return true;
    if (selectedComponentId === component.id) return true;
    return false;
  }, [selectedComponentId, componentId, component.id]);
  const isHovered = hoveredComponentId === componentId;
  const isDragging = draggedNodeId === componentId;
  const isDropTarget = dropTargetId === componentId;

  // 클릭 핸들러
  const handleClick = useCallback(
    (e: MouseEvent) => {
      e.stopPropagation();
      onNodeClick?.(componentId);
    },
    [componentId, onNodeClick]
  );

  // 더블클릭 핸들러
  const handleDoubleClick = useCallback(
    (e: MouseEvent) => {
      e.stopPropagation();
      onNodeDoubleClick?.(componentId);
    },
    [componentId, onNodeDoubleClick]
  );

  // 확장/접힘 토글 핸들러
  const handleToggle = useCallback(
    (e: MouseEvent) => {
      e.stopPropagation();
      onToggleExpand(componentId);
    },
    [componentId, onToggleExpand]
  );

  // 마우스 진입/이탈 핸들러
  const handleMouseEnter = useCallback(() => {
    setHoveredComponentId(componentId);
  }, [componentId, setHoveredComponentId]);

  const handleMouseLeave = useCallback(() => {
    setHoveredComponentId(null);
  }, [setHoveredComponentId]);

  // 드래그 핸들러
  const handleDragStart = useCallback(
    (e: DragEvent) => {
      if (!enableDragDrop) return;
      onDragStart(e, componentId);
    },
    [componentId, enableDragDrop, onDragStart]
  );

  const handleDragOver = useCallback(
    (e: DragEvent) => {
      if (!enableDragDrop) return;
      e.preventDefault();
      onDragOver(e, componentId);
    },
    [componentId, enableDragDrop, onDragOver]
  );

  const handleDrop = useCallback(
    (e: DragEvent) => {
      if (!enableDragDrop) return;
      e.preventDefault();

      // dropPosition이 null일 수 있으므로 이벤트에서 직접 계산
      // React 상태 업데이트 타이밍 문제를 방지
      const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
      const y = e.clientY - rect.top;
      const height = rect.height;

      let calculatedPosition: DropPosition;
      if (y < height * 0.25) {
        calculatedPosition = 'before';
      } else if (y > height * 0.75) {
        calculatedPosition = 'after';
      } else {
        calculatedPosition = 'inside';
      }

      onDrop(e, componentId, calculatedPosition);
    },
    [componentId, enableDragDrop, onDrop]
  );

  // 스타일 클래스 생성
  const nodeClasses = useMemo(() => {
    const classes = [
      'flex items-center gap-1 px-2 py-1 rounded cursor-pointer',
      'transition-colors duration-150',
      'hover:bg-gray-100 dark:hover:bg-gray-700',
    ];

    if (isSelected) {
      classes.push('bg-blue-100 dark:bg-blue-900');
    } else if (isHovered) {
      classes.push('bg-gray-50 dark:bg-gray-800');
    }

    if (isDragging) {
      classes.push('opacity-50');
    }

    if (isDropTarget) {
      if (dropPosition === 'before') {
        classes.push('border-t-2 border-blue-500');
      } else if (dropPosition === 'after') {
        classes.push('border-b-2 border-blue-500');
      } else if (dropPosition === 'inside') {
        classes.push('ring-2 ring-blue-500');
      }
    }

    return classes.join(' ');
  }, [isSelected, isHovered, isDragging, isDropTarget, dropPosition]);

  return (
    <div className="select-none">
      {/* 노드 행 */}
      <div
        className={nodeClasses}
        style={{ paddingLeft: `${depth * 16 + 8}px` }}
        onClick={handleClick}
        onDoubleClick={handleDoubleClick}
        onMouseEnter={handleMouseEnter}
        onMouseLeave={handleMouseLeave}
        draggable={enableDragDrop}
        onDragStart={handleDragStart}
        onDragOver={handleDragOver}
        onDrop={handleDrop}
        onDragEnd={onDragEnd}
        role="treeitem"
        aria-selected={isSelected}
        aria-expanded={hasChildren ? isExpanded : undefined}
      >
        {/* 확장/접힘 버튼 */}
        {hasChildren ? (
          <button
            className="w-4 h-4 flex items-center justify-center text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            onClick={handleToggle}
            aria-label={isExpanded ? '접기' : '펼치기'}
          >
            {isExpanded ? '▼' : '▶'}
          </button>
        ) : (
          <span className="w-4" />
        )}

        {/* 아이콘 */}
        <span className="text-sm" title={nodeInfo.type}>
          {getNodeIcon(nodeInfo.type)}
        </span>

        {/* 이름 */}
        <span className={`text-sm font-medium ${getNodeColorClass(nodeInfo)}`}>
          {nodeInfo.name}
        </span>

        {/* ID (축약) - 실제 ID가 있는 경우만 표시 */}
        {component.id ? (
          <span className="text-xs text-gray-400 dark:text-gray-500 ml-1">
            #{component.id.length > 10 ? component.id.slice(0, 8) + '…' : component.id}
          </span>
        ) : (
          <span className="text-xs text-gray-300 dark:text-gray-600 ml-1" title="ID 미정의">
            (no id)
          </span>
        )}

        {/* 배지들 */}
        <div className="flex items-center gap-1 ml-auto">
          {/* 조건부 렌더링 표시 */}
          {nodeInfo.hasCondition && (
            <span
              className="px-1 text-xs bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300 rounded"
              title={`조건: ${component.if}`}
            >
              if
            </span>
          )}

          {/* 반복 렌더링 표시 */}
          {nodeInfo.hasIteration && (
            <span
              className="px-1 text-xs bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded"
              title={`반복: ${component.iteration?.source}`}
            >
              ∞
            </span>
          )}

          {/* 확장 출처 표시 */}
          {nodeInfo.isFromExtension && nodeInfo.extensionSource && (
            <span
              className="px-1 text-xs bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 rounded"
              title={`출처: ${nodeInfo.extensionSource}`}
            >
              {nodeInfo.extensionSource}
            </span>
          )}
        </div>
      </div>

      {/* 자식 노드들 */}
      {hasChildren && isExpanded && (
        <div role="group">
          {component.children!.map((child, index) => {
            // 문자열인 경우 (text node) 스킵
            if (typeof child === 'string') return null;

            // 자식 경로 생성 (treePath.index 형식)
            const childPath = `${treePath}.${index}`;
            const childKey = child.id || `tree_${childPath}`;

            return (
              <TreeNode
                key={childKey}
                component={child}
                depth={depth + 1}
                treePath={childPath}
                enableDragDrop={enableDragDrop}
                expandedNodes={expandedNodes}
                onToggleExpand={onToggleExpand}
                onNodeClick={onNodeClick}
                onNodeDoubleClick={onNodeDoubleClick}
                onDragStart={onDragStart}
                onDragOver={onDragOver}
                onDrop={onDrop}
                onDragEnd={onDragEnd}
                draggedNodeId={draggedNodeId}
                dropTargetId={dropTargetId}
                dropPosition={dropPosition}
              />
            );
          })}
        </div>
      )}
    </div>
  );
});

// ============================================================================
// ContextMenu 컴포넌트 (React.memo로 최적화)
// ============================================================================

interface ContextMenuProps {
  x: number;
  y: number;
  nodeId: string;
  onClose: () => void;
}

const ContextMenu = React.memo(function ContextMenu({ x, y, nodeId, onClose }: ContextMenuProps) {
  const deleteComponent = useEditorState((state) => state.deleteComponent);
  const duplicateComponent = useEditorState((state) => state.duplicateComponent);
  const copyToClipboard = useEditorState((state) => state.copyToClipboard);
  const pasteFromClipboard = useEditorState((state) => state.pasteFromClipboard);
  const clipboard = useEditorState((state) => state.clipboard);
  const layoutData = useEditorState((state) => state.layoutData);

  // 현재 노드 찾기
  const currentComponent = useMemo(() => {
    if (!layoutData) return null;

    const findComponent = (
      components: (ComponentDefinition | string)[]
    ): ComponentDefinition | null => {
      for (const comp of components) {
        if (typeof comp === 'string') continue;
        if (comp.id === nodeId) return comp;
        if (comp.children) {
          const found = findComponent(comp.children);
          if (found) return found;
        }
      }
      return null;
    };

    return findComponent(layoutData.components as (ComponentDefinition | string)[]);
  }, [layoutData, nodeId]);

  const handleCopy = useCallback(() => {
    if (currentComponent) {
      copyToClipboard(currentComponent);
      logger.log('Copied component:', nodeId);
    }
    onClose();
  }, [currentComponent, copyToClipboard, nodeId, onClose]);

  const handlePaste = useCallback(() => {
    if (clipboard) {
      pasteFromClipboard(nodeId, 'inside');
      logger.log('Pasted component inside:', nodeId);
    }
    onClose();
  }, [clipboard, pasteFromClipboard, nodeId, onClose]);

  const handleDuplicate = useCallback(() => {
    duplicateComponent(nodeId);
    logger.log('Duplicated component:', nodeId);
    onClose();
  }, [duplicateComponent, nodeId, onClose]);

  const handleDelete = useCallback(() => {
    deleteComponent(nodeId);
    logger.log('Deleted component:', nodeId);
    onClose();
  }, [deleteComponent, nodeId, onClose]);

  // 외부 클릭 시 닫기
  React.useEffect(() => {
    const handleClickOutside = () => onClose();
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, [onClose]);

  return (
    <div
      className="fixed z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 min-w-32"
      style={{ left: x, top: y }}
      onClick={(e) => e.stopPropagation()}
    >
      <button
        className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
        onClick={handleCopy}
      >
        📋 복사
      </button>
      <button
        className={`w-full px-4 py-2 text-left text-sm ${
          clipboard
            ? 'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300'
            : 'text-gray-400 dark:text-gray-600 cursor-not-allowed'
        }`}
        onClick={handlePaste}
        disabled={!clipboard}
      >
        📥 붙여넣기
      </button>
      <button
        className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
        onClick={handleDuplicate}
      >
        📑 복제
      </button>
      <div className="border-t border-gray-200 dark:border-gray-700 my-1" />
      <button
        className="w-full px-4 py-2 text-left text-sm hover:bg-red-50 dark:hover:bg-red-900 text-red-600 dark:text-red-400"
        onClick={handleDelete}
      >
        🗑️ 삭제
      </button>
    </div>
  );
});

// ============================================================================
// LayoutTree 메인 컴포넌트
// ============================================================================

export function LayoutTree({
  className = '',
  showContextMenu = true,
  enableDragDrop = true,
  onNodeClick,
  onNodeDoubleClick,
}: LayoutTreeProps) {
  const layoutData = useEditorState((state) => state.layoutData);
  const selectComponent = useEditorState((state) => state.selectComponent);
  const moveComponent = useEditorState((state) => state.moveComponent);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);

  // 확장/접힘 상태
  const [expandedNodes, setExpandedNodes] = useState<Set<string>>(new Set());

  // 드래그 상태
  const [draggedNodeId, setDraggedNodeId] = useState<string | null>(null);
  const [dropTargetId, setDropTargetId] = useState<string | null>(null);
  const [dropPosition, setDropPosition] = useState<DropPosition | null>(null);

  // 컨텍스트 메뉴 상태
  const [contextMenu, setContextMenu] = useState<ContextMenuState>({
    visible: false,
    x: 0,
    y: 0,
    nodeId: null,
  });

  // 초기 확장 상태 설정 (최상위 레벨만 확장)
  React.useEffect(() => {
    if (layoutData) {
      const initialExpanded = new Set<string>();
      layoutData.components.forEach((comp, index) => {
        if (typeof comp !== 'string') {
          // comp.id가 없는 경우 treePath 기반 ID 사용
          const typedComp = comp as ComponentDefinition;
          const compId = typedComp.id || `tree_${index}`;
          initialExpanded.add(compId);
        }
      });
      setExpandedNodes(initialExpanded);
    }
  }, [layoutData?.layout_name]); // layout_name이 변경될 때만 재설정

  // 선택된 컴포넌트가 변경되면 해당 노드까지 자동 펼침
  // Note: selectComponent에서 auto_path: ID가 영구 ID로 정규화되므로
  // 여기서는 단순히 ID로 경로를 찾으면 됨
  React.useEffect(() => {
    if (!selectedComponentId || !layoutData) return;

    const pathToNode = findPathToComponent(
      layoutData.components as (ComponentDefinition | string)[],
      selectedComponentId
    );

    if (pathToNode && pathToNode.length > 0) {
      setExpandedNodes((prev) => {
        const next = new Set(prev);
        // 마지막 노드(선택된 노드)를 제외한 경로의 모든 노드를 펼침
        pathToNode.slice(0, -1).forEach((id) => next.add(id));
        return next;
      });
    }
  }, [selectedComponentId, layoutData]);

  // 확장/접힘 토글
  const handleToggleExpand = useCallback((id: string) => {
    setExpandedNodes((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  // 노드 클릭 핸들러
  // tree_ ID를 auto_path: 형식으로 변환하여 PreviewCanvas/PropertyPanel과 호환
  const handleNodeClick = useCallback(
    (id: string) => {
      let effectiveId = id;

      // tree_0.2.1 형식인 경우 auto_path:0.children.2.children.1 형식으로 변환
      if (id.startsWith('tree_')) {
        const treePath = id.substring('tree_'.length);
        const dynamicPath = convertTreePathToDynamicPath(treePath);
        effectiveId = `auto_path:${dynamicPath}`;
      }

      selectComponent(effectiveId);
      onNodeClick?.(effectiveId);
    },
    [selectComponent, onNodeClick]
  );

  // 컨텍스트 메뉴 핸들러
  const handleContextMenu = useCallback(
    (e: MouseEvent) => {
      if (!showContextMenu) return;

      e.preventDefault();
      const target = e.target as HTMLElement;
      const nodeElement = target.closest('[role="treeitem"]');

      if (nodeElement) {
        // 클릭된 노드 ID 추출 (data-id 또는 aria-label에서)
        const nodeId = (nodeElement as HTMLElement).getAttribute('data-node-id');
        if (nodeId) {
          setContextMenu({
            visible: true,
            x: e.clientX,
            y: e.clientY,
            nodeId,
          });
        }
      }
    },
    [showContextMenu]
  );

  // 드래그 핸들러들
  const handleDragStart = useCallback((e: DragEvent, id: string) => {
    setDraggedNodeId(id);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', id);
    logger.log('Drag start:', id);
  }, []);

  const handleDragOver = useCallback(
    (e: DragEvent, id: string) => {
      if (draggedNodeId === id) return;

      const rect = (e.target as HTMLElement).getBoundingClientRect();
      const y = e.clientY - rect.top;
      const height = rect.height;

      let position: DropPosition;
      if (y < height * 0.25) {
        position = 'before';
      } else if (y > height * 0.75) {
        position = 'after';
      } else {
        position = 'inside';
      }

      setDropTargetId(id);
      setDropPosition(position);
    },
    [draggedNodeId]
  );

  const handleDrop = useCallback(
    (_e: DragEvent, targetId: string, position: DropPosition) => {
      if (draggedNodeId && draggedNodeId !== targetId) {
        moveComponent(draggedNodeId, targetId, position);
        logger.log('Dropped:', draggedNodeId, 'to', targetId, position);
      }

      setDraggedNodeId(null);
      setDropTargetId(null);
      setDropPosition(null);
    },
    [draggedNodeId, moveComponent]
  );

  const handleDragEnd = useCallback(() => {
    setDraggedNodeId(null);
    setDropTargetId(null);
    setDropPosition(null);
  }, []);

  // 컨텍스트 메뉴 닫기
  const handleCloseContextMenu = useCallback(() => {
    setContextMenu({ visible: false, x: 0, y: 0, nodeId: null });
  }, []);

  // 레이아웃 데이터가 없으면 빈 상태 표시
  if (!layoutData) {
    return (
      <div
        className={`flex items-center justify-center h-full text-gray-500 dark:text-gray-400 ${className}`}
      >
        <p>레이아웃을 로드하세요</p>
      </div>
    );
  }

  return (
    <div
      className={`h-full overflow-auto bg-white dark:bg-gray-900 ${className}`}
      onContextMenu={handleContextMenu}
      role="tree"
      aria-label="레이아웃 트리"
    >
      {/* 헤더 */}
      <div className="sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-3 py-2">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
          레이아웃 트리
        </h3>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {layoutData.layout_name}
          {(layoutData as { extends?: string }).extends && (
            <span className="ml-1 text-blue-500">
              (extends: {(layoutData as { extends?: string }).extends})
            </span>
          )}
        </p>
      </div>

      {/* 트리 노드들 */}
      <div className="p-2">
        {layoutData.components.map((component, index) => {
          if (typeof component === 'string') return null;

          const typedComponent = component as ComponentDefinition;
          const rootPath = `${index}`;
          const componentKey = typedComponent.id || `tree_${rootPath}`;

          return (
            <TreeNode
              key={componentKey}
              component={typedComponent}
              depth={0}
              treePath={rootPath}
              enableDragDrop={enableDragDrop}
              expandedNodes={expandedNodes}
              onToggleExpand={handleToggleExpand}
              onNodeClick={handleNodeClick}
              onNodeDoubleClick={onNodeDoubleClick}
              onDragStart={handleDragStart}
              onDragOver={handleDragOver}
              onDrop={handleDrop}
              onDragEnd={handleDragEnd}
              draggedNodeId={draggedNodeId}
              dropTargetId={dropTargetId}
              dropPosition={dropPosition}
            />
          );
        })}
      </div>

      {/* 컨텍스트 메뉴 */}
      {contextMenu.visible && contextMenu.nodeId && (
        <ContextMenu
          x={contextMenu.x}
          y={contextMenu.y}
          nodeId={contextMenu.nodeId}
          onClose={handleCloseContextMenu}
        />
      )}
    </div>
  );
}

// ============================================================================
// 유틸리티 훅
// ============================================================================

/**
 * 트리 확장/접힘 상태 관리 훅
 */
export function useTreeExpansion(initialExpanded: string[] = []) {
  const [expandedNodes, setExpandedNodes] = useState<Set<string>>(
    new Set(initialExpanded)
  );

  const toggleNode = useCallback((id: string) => {
    setExpandedNodes((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  const expandAll = useCallback((ids: string[]) => {
    setExpandedNodes(new Set(ids));
  }, []);

  const collapseAll = useCallback(() => {
    setExpandedNodes(new Set());
  }, []);

  const isExpanded = useCallback(
    (id: string) => expandedNodes.has(id),
    [expandedNodes]
  );

  return {
    expandedNodes,
    toggleNode,
    expandAll,
    collapseAll,
    isExpanded,
  };
}

// ============================================================================
// Export
// ============================================================================

export default LayoutTree;
