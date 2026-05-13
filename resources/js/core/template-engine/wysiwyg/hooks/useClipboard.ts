/**
 * useClipboard.ts
 *
 * G7 위지윅 레이아웃 편집기의 클립보드 훅
 *
 * 역할:
 * - 컴포넌트 복사/잘라내기/붙여넣기
 * - 시스템 클립보드 연동 (JSON 형식)
 * - 컴포넌트 ID 재생성
 */

import { useCallback, useMemo } from 'react';
import { useEditorState } from './useEditorState';
import type { ComponentDefinition, DropPosition } from '../types/editor';
import { generateUniqueId, findComponentById } from '../utils/layoutUtils';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Core:Clipboard')) ?? {
    log: (...args: unknown[]) => console.log('[Core:Clipboard]', ...args),
    warn: (...args: unknown[]) => console.warn('[Core:Clipboard]', ...args),
    error: (...args: unknown[]) => console.error('[Core:Clipboard]', ...args),
};

// ============================================================================
// 타입 정의
// ============================================================================

export interface ClipboardData {
  /** 클립보드 데이터 타입 */
  type: 'g7-component' | 'g7-components';
  /** 단일 컴포넌트 또는 컴포넌트 배열 */
  data: ComponentDefinition | ComponentDefinition[];
  /** 복사 시간 */
  timestamp: number;
  /** 출처 레이아웃 */
  sourceLayout?: string;
}

export interface UseClipboardReturn {
  /** 현재 클립보드 내용 */
  clipboard: ComponentDefinition | null;

  /** 클립보드에 내용이 있는지 */
  hasClipboardContent: boolean;

  /** 컴포넌트 복사 */
  copy: (componentId?: string) => void;

  /** 컴포넌트 잘라내기 */
  cut: (componentId?: string) => void;

  /** 컴포넌트 붙여넣기 */
  paste: (targetId?: string, position?: DropPosition) => void;

  /** 시스템 클립보드에서 읽기 */
  readFromSystemClipboard: () => Promise<ComponentDefinition | null>;

  /** 시스템 클립보드에 쓰기 */
  writeToSystemClipboard: (component: ComponentDefinition) => Promise<boolean>;

  /** 클립보드 초기화 */
  clear: () => void;

  /** 붙여넣기 가능 여부 */
  canPaste: boolean;

  /** 복사된 컴포넌트 정보 */
  clipboardInfo: {
    name: string;
    type: string;
    hasChildren: boolean;
  } | null;
}

// ============================================================================
// 유틸리티 함수
// ============================================================================

/**
 * 컴포넌트와 모든 자식의 ID를 재생성
 */
function regenerateIds(component: ComponentDefinition): ComponentDefinition {
  const newComponent: ComponentDefinition = {
    ...component,
    id: generateUniqueId(component.name),
  };

  if (component.children && component.children.length > 0) {
    newComponent.children = component.children.map((child) => regenerateIds(child));
  }

  return newComponent;
}

/**
 * 컴포넌트를 JSON 문자열로 직렬화
 */
function serializeComponent(component: ComponentDefinition): string {
  const clipboardData: ClipboardData = {
    type: 'g7-component',
    data: component,
    timestamp: Date.now(),
  };
  return JSON.stringify(clipboardData, null, 2);
}

/**
 * JSON 문자열에서 컴포넌트 역직렬화
 */
function deserializeComponent(json: string): ComponentDefinition | null {
  try {
    const parsed = JSON.parse(json);

    // 그누보드7 클립보드 데이터 형식 확인
    if (parsed.type === 'g7-component' && parsed.data) {
      return parsed.data as ComponentDefinition;
    }

    // 레거시 형식 또는 직접 컴포넌트 형식
    if (parsed.id && parsed.type && parsed.name) {
      return parsed as ComponentDefinition;
    }

    return null;
  } catch {
    return null;
  }
}

// ============================================================================
// 훅 구현
// ============================================================================

/**
 * 클립보드 관리 훅
 *
 * 컴포넌트의 복사/잘라내기/붙여넣기를 관리합니다.
 */
export function useClipboard(): UseClipboardReturn {
  // Zustand 상태
  const clipboard = useEditorState((state) => state.clipboard);
  const layoutData = useEditorState((state) => state.layoutData);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const copyToClipboard = useEditorState((state) => state.copyToClipboard);
  const pasteFromClipboard = useEditorState((state) => state.pasteFromClipboard);
  const deleteComponent = useEditorState((state) => state.deleteComponent);
  const addComponent = useEditorState((state) => state.addComponent);

  // 클립보드에 내용 있는지
  const hasClipboardContent = useMemo(() => clipboard !== null, [clipboard]);

  // 붙여넣기 가능 여부
  const canPaste = useMemo(() => {
    return hasClipboardContent && layoutData !== null;
  }, [hasClipboardContent, layoutData]);

  // 클립보드 컴포넌트 정보
  const clipboardInfo = useMemo(() => {
    if (!clipboard) return null;
    return {
      name: clipboard.name,
      type: clipboard.type,
      hasChildren: !!(clipboard.children && clipboard.children.length > 0),
    };
  }, [clipboard]);

  // 컴포넌트 복사
  const copy = useCallback(
    (componentId?: string) => {
      const targetId = componentId || selectedComponentId;
      if (!targetId || !layoutData) return;

      const result = findComponentById(layoutData.components, targetId);
      if (result) {
        copyToClipboard(result.component);
      }
    },
    [layoutData, selectedComponentId, copyToClipboard]
  );

  // 컴포넌트 잘라내기
  const cut = useCallback(
    (componentId?: string) => {
      const targetId = componentId || selectedComponentId;
      if (!targetId || !layoutData) return;

      const result = findComponentById(layoutData.components, targetId);
      if (result) {
        copyToClipboard(result.component);
        deleteComponent(targetId);
      }
    },
    [layoutData, selectedComponentId, copyToClipboard, deleteComponent]
  );

  // 컴포넌트 붙여넣기
  const paste = useCallback(
    (targetId?: string, position: DropPosition = 'after') => {
      if (!clipboard || !layoutData) return;

      // ID 재생성
      const newComponent = regenerateIds(clipboard);

      // 타겟이 지정되지 않은 경우 선택된 컴포넌트 또는 최상위에 붙여넣기
      const actualTargetId = targetId || selectedComponentId;

      if (actualTargetId) {
        pasteFromClipboard(actualTargetId, position);
      } else {
        // 최상위에 추가
        addComponent(newComponent, undefined, 'inside');
      }
    },
    [clipboard, layoutData, selectedComponentId, pasteFromClipboard, addComponent]
  );

  // 시스템 클립보드에서 읽기
  const readFromSystemClipboard = useCallback(async (): Promise<ComponentDefinition | null> => {
    try {
      // Clipboard API 지원 확인
      if (!navigator.clipboard?.readText) {
        return null;
      }

      const text = await navigator.clipboard.readText();
      const component = deserializeComponent(text);

      if (component) {
        // 앱 클립보드에도 저장
        copyToClipboard(component);
        return component;
      }

      return null;
    } catch (error) {
      // 클립보드 접근 권한 없음 또는 에러
      logger.warn('Failed to read from system clipboard:', error);
      return null;
    }
  }, [copyToClipboard]);

  // 시스템 클립보드에 쓰기
  const writeToSystemClipboard = useCallback(
    async (component: ComponentDefinition): Promise<boolean> => {
      try {
        // Clipboard API 지원 확인
        if (!navigator.clipboard?.writeText) {
          return false;
        }

        const serialized = serializeComponent(component);
        await navigator.clipboard.writeText(serialized);

        // 앱 클립보드에도 저장
        copyToClipboard(component);
        return true;
      } catch (error) {
        // 클립보드 접근 권한 없음 또는 에러
        logger.warn('Failed to write to system clipboard:', error);
        return false;
      }
    },
    [copyToClipboard]
  );

  // 클립보드 초기화
  const clear = useCallback(() => {
    useEditorState.setState({ clipboard: null });
  }, []);

  return {
    clipboard,
    hasClipboardContent,
    copy,
    cut,
    paste,
    readFromSystemClipboard,
    writeToSystemClipboard,
    clear,
    canPaste,
    clipboardInfo,
  };
}

// ============================================================================
// Export
// ============================================================================

export default useClipboard;
export { regenerateIds, serializeComponent, deserializeComponent };
