/**
 * useDragDrop.ts
 *
 * 그누보드7 위지윅 레이아웃 편집기 - 드래그앤드롭 훅
 *
 * 역할:
 * - 컴포넌트 드래그앤드롭 상태 관리
 * - 드래그 시작/진행/종료 이벤트 처리
 * - 드롭 위치 계산 (before, after, inside)
 * - 레이아웃 트리와 캔버스 간 드래그 지원
 *
 * Phase 2: 핵심 편집 기능
 */

import { useCallback, useRef, useMemo } from 'react';
import { useEditorState } from './useEditorState';
import type {
  DropPosition,
  ComponentDefinition,
  ExtensionComponent,
} from '../types/editor';
import type { ComponentMetadata } from '../../ComponentRegistry';
import { generateUniqueId } from '../utils/layoutUtils';

// ============================================================================
// 타입 정의
// ============================================================================

/** 드래그 소스 타입 */
export type DragSourceType = 'palette' | 'tree' | 'canvas';

/** 드래그 아이템 데이터 */
export interface DragItemData {
  /** 드래그 소스 */
  source: DragSourceType;
  /** 팔레트에서 드래그한 경우: 컴포넌트 메타데이터 */
  componentMetadata?: ComponentMetadata | ExtensionComponent;
  /** 트리/캔버스에서 드래그한 경우: 기존 컴포넌트 ID */
  componentId?: string;
  /** 확장 컴포넌트 여부 */
  isExtension?: boolean;
  /** 확장 출처 */
  extensionSource?: string;
}

/** 드롭 대상 정보 */
export interface DropTargetInfo {
  /** 대상 컴포넌트 ID */
  targetId: string;
  /** 드롭 위치 */
  position: DropPosition;
  /** 유효한 드롭 대상인지 */
  isValid: boolean;
  /** 무효 사유 (있는 경우) */
  invalidReason?: string;
}

/** useDragDrop 반환 타입 */
export interface UseDragDropReturn {
  /** 현재 드래그 중인지 여부 */
  isDragging: boolean;
  /** 드래그 중인 아이템 데이터 */
  dragItem: DragItemData | null;
  /** 현재 드롭 대상 정보 */
  dropTarget: DropTargetInfo | null;
  /** 드래그 시작 핸들러 */
  handleDragStart: (
    source: DragSourceType,
    data: ComponentMetadata | ExtensionComponent | string
  ) => void;
  /** 드래그 오버 핸들러 */
  handleDragOver: (
    e: React.DragEvent,
    targetId: string,
    targetElement: HTMLElement
  ) => void;
  /** 드래그 종료 핸들러 */
  handleDragEnd: () => void;
  /** 드롭 핸들러 */
  handleDrop: (e: React.DragEvent, targetId: string) => void;
  /** 드래그 엔터 핸들러 */
  handleDragEnter: (e: React.DragEvent, targetId: string) => void;
  /** 드래그 리브 핸들러 */
  handleDragLeave: (e: React.DragEvent) => void;
  /** 팔레트 아이템에서 컴포넌트 정의 생성 */
  createComponentFromPalette: (
    metadata: ComponentMetadata | ExtensionComponent,
    isExtension: boolean
  ) => ComponentDefinition;
  /** 특정 위치에 드롭 가능한지 확인 */
  canDropAt: (targetId: string, position: DropPosition) => boolean;
  /** 드롭 위치 계산 */
  calculateDropPosition: (
    e: React.DragEvent,
    targetElement: HTMLElement
  ) => DropPosition;
}

// ============================================================================
// 드롭 위치 계산 상수
// ============================================================================

/** 상단/하단 드롭 영역 비율 (0.25 = 상하 25%씩) */
const DROP_EDGE_THRESHOLD = 0.25;

// ============================================================================
// useDragDrop Hook
// ============================================================================

export function useDragDrop(): UseDragDropReturn {
  // Zustand 스토어에서 필요한 상태와 액션 가져오기
  const {
    draggingComponent,
    dropTargetId,
    dropPosition,
    layoutData,
    startDrag,
    dragOver,
    endDrag,
    addComponent,
    moveComponent,
  } = useEditorState();

  // 드래그 아이템 참조 (이벤트 핸들러에서 접근용)
  const dragItemRef = useRef<DragItemData | null>(null);

  // 현재 드래그 중인지 여부
  const isDragging = useMemo(() => draggingComponent !== null, [draggingComponent]);

  // 드래그 아이템 데이터
  const dragItem = useMemo((): DragItemData | null => {
    if (!draggingComponent) return null;

    // ComponentMetadata 또는 ExtensionComponent인 경우
    if ('name' in draggingComponent && !('id' in draggingComponent && 'type' in draggingComponent && 'props' in draggingComponent)) {
      const metadata = draggingComponent as ComponentMetadata | ExtensionComponent;
      const isExtension = 'source' in metadata && 'sourceType' in metadata;
      return {
        source: 'palette',
        componentMetadata: metadata,
        isExtension,
        extensionSource: isExtension ? (metadata as ExtensionComponent).source : undefined,
      };
    }

    // ComponentDefinition인 경우 (트리/캔버스에서 드래그)
    const component = draggingComponent as ComponentDefinition;
    return {
      source: 'tree',
      componentId: component.id,
    };
  }, [draggingComponent]);

  // 내부: 드롭 가능 여부 확인 (dropTarget useMemo보다 먼저 정의)
  const canDropAtInternal = useCallback((targetId: string, position: DropPosition): boolean => {
    // 자기 자신에게 드롭 불가
    if (dragItemRef.current?.componentId === targetId) {
      return false;
    }

    // 자식 컴포넌트에 부모를 드롭하려는 경우 방지 (순환 참조)
    if (dragItemRef.current?.componentId && layoutData) {
      const isDescendant = checkIsDescendant(
        layoutData.components,
        dragItemRef.current.componentId,
        targetId
      );
      if (isDescendant) {
        return false;
      }
    }

    // TODO: 추가 검증 로직 (컴포넌트 타입 호환성 등)
    return true;
  }, [layoutData]);

  // 현재 드롭 대상 정보
  const dropTarget = useMemo((): DropTargetInfo | null => {
    if (!dropTargetId || !dropPosition) return null;

    const isValid = canDropAtInternal(dropTargetId, dropPosition);
    return {
      targetId: dropTargetId,
      position: dropPosition,
      isValid,
      invalidReason: isValid ? undefined : '이 위치에 드롭할 수 없습니다',
    };
  }, [dropTargetId, dropPosition, canDropAtInternal]);

  // 드롭 위치 계산
  const calculateDropPosition = useCallback((
    e: React.DragEvent,
    targetElement: HTMLElement
  ): DropPosition => {
    const rect = targetElement.getBoundingClientRect();
    const y = e.clientY - rect.top;
    const relativeY = y / rect.height;

    // 상단 25%: before
    if (relativeY < DROP_EDGE_THRESHOLD) {
      return 'before';
    }

    // 하단 25%: after
    if (relativeY > 1 - DROP_EDGE_THRESHOLD) {
      return 'after';
    }

    // 중앙 50%: inside (자식으로 추가)
    return 'inside';
  }, []);

  // 드래그 시작
  const handleDragStart = useCallback((
    source: DragSourceType,
    data: ComponentMetadata | ExtensionComponent | string
  ) => {
    let dragData: DragItemData;

    if (typeof data === 'string') {
      // 컴포넌트 ID (트리/캔버스에서 드래그)
      dragData = {
        source,
        componentId: data,
      };
    } else {
      // 메타데이터 (팔레트에서 드래그)
      const isExtension = 'source' in data && 'sourceType' in data;
      dragData = {
        source,
        componentMetadata: data,
        isExtension,
        extensionSource: isExtension ? (data as ExtensionComponent).source : undefined,
      };
    }

    dragItemRef.current = dragData;

    // Zustand 스토어 업데이트
    if (typeof data === 'string') {
      // 기존 컴포넌트를 찾아서 startDrag에 전달
      const component = findComponentById(layoutData?.components || [], data);
      if (component) {
        startDrag(component);
      }
    } else {
      startDrag(data);
    }
  }, [layoutData, startDrag]);

  // 드래그 오버
  const handleDragOver = useCallback((
    e: React.DragEvent,
    targetId: string,
    targetElement: HTMLElement
  ) => {
    e.preventDefault();
    e.stopPropagation();

    const position = calculateDropPosition(e, targetElement);
    dragOver(targetId, position);
  }, [calculateDropPosition, dragOver]);

  // 드래그 엔터
  const handleDragEnter = useCallback((e: React.DragEvent, targetId: string) => {
    e.preventDefault();
    e.stopPropagation();
  }, []);

  // 드래그 리브
  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
  }, []);

  // 드래그 종료
  const handleDragEnd = useCallback(() => {
    dragItemRef.current = null;
    endDrag();
  }, [endDrag]);

  // 드롭
  const handleDrop = useCallback((e: React.DragEvent, targetId: string) => {
    e.preventDefault();
    e.stopPropagation();

    const currentDragItem = dragItemRef.current;
    if (!currentDragItem || !dropPosition) {
      handleDragEnd();
      return;
    }

    // 유효성 검사
    if (!canDropAtInternal(targetId, dropPosition)) {
      handleDragEnd();
      return;
    }

    if (currentDragItem.source === 'palette' && currentDragItem.componentMetadata) {
      // 팔레트에서 새 컴포넌트 추가
      const newComponent = createComponentFromPaletteInternal(
        currentDragItem.componentMetadata,
        currentDragItem.isExtension || false
      );
      addComponent(newComponent, targetId, dropPosition);
    } else if (currentDragItem.componentId) {
      // 기존 컴포넌트 이동
      moveComponent(currentDragItem.componentId, targetId, dropPosition);
    }

    handleDragEnd();
  }, [dropPosition, canDropAtInternal, addComponent, moveComponent, handleDragEnd]);

  // 팔레트 아이템에서 컴포넌트 정의 생성
  const createComponentFromPaletteInternal = useCallback((
    metadata: ComponentMetadata | ExtensionComponent,
    isExtension: boolean
  ): ComponentDefinition => {
    const uniqueId = generateUniqueId(metadata.name.toLowerCase());

    // 기본 props 생성
    const defaultProps: Record<string, any> = {};
    if ('props' in metadata && metadata.props) {
      Object.entries(metadata.props).forEach(([key, schema]) => {
        if ('defaultValue' in schema && schema.defaultValue !== undefined) {
          defaultProps[key] = schema.defaultValue;
        }
      });
    }

    // ExtensionComponent의 경우 type 속성 사용
    const componentType = isExtension
      ? (metadata as ExtensionComponent).type
      : ('type' in metadata ? metadata.type : 'basic');

    return {
      id: uniqueId,
      type: componentType,
      name: metadata.name,
      props: defaultProps,
    };
  }, []);

  // 드롭 가능 여부 확인 (외부 API)
  const canDropAt = useCallback((targetId: string, position: DropPosition): boolean => {
    return canDropAtInternal(targetId, position);
  }, [canDropAtInternal]);

  // 팔레트 아이템에서 컴포넌트 정의 생성 (외부 API)
  const createComponentFromPalette = useCallback((
    metadata: ComponentMetadata | ExtensionComponent,
    isExtension: boolean
  ): ComponentDefinition => {
    return createComponentFromPaletteInternal(metadata, isExtension);
  }, [createComponentFromPaletteInternal]);

  return {
    isDragging,
    dragItem,
    dropTarget,
    handleDragStart,
    handleDragOver,
    handleDragEnd,
    handleDrop,
    handleDragEnter,
    handleDragLeave,
    createComponentFromPalette,
    canDropAt,
    calculateDropPosition,
  };
}

// ============================================================================
// 헬퍼 함수
// ============================================================================

/**
 * 컴포넌트 배열에서 ID로 컴포넌트 찾기
 */
function findComponentById(
  components: any[],
  id: string
): ComponentDefinition | null {
  for (const component of components) {
    if (component.id === id) {
      return component;
    }
    if (component.children && Array.isArray(component.children)) {
      const found = findComponentById(component.children, id);
      if (found) return found;
    }
  }
  return null;
}

/**
 * sourceId가 targetId의 조상인지 확인 (순환 참조 방지)
 */
function checkIsDescendant(
  components: any[],
  sourceId: string,
  targetId: string
): boolean {
  const sourceComponent = findComponentById(components, sourceId);
  if (!sourceComponent) return false;

  // sourceComponent의 자손 중에 targetId가 있는지 확인
  return hasDescendant(sourceComponent, targetId);
}

/**
 * 컴포넌트의 자손 중에 특정 ID가 있는지 확인
 */
function hasDescendant(component: any, targetId: string): boolean {
  if (!component.children || !Array.isArray(component.children)) {
    return false;
  }

  for (const child of component.children) {
    if (child.id === targetId) {
      return true;
    }
    if (hasDescendant(child, targetId)) {
      return true;
    }
  }

  return false;
}

export default useDragDrop;
