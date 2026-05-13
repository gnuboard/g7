/**
 * useKeyboardShortcuts.ts
 *
 * G7 위지윅 레이아웃 편집기의 키보드 단축키 훅
 *
 * 역할:
 * - 전역 키보드 단축키 등록
 * - Undo/Redo, 복사/붙여넣기, 삭제 등 처리
 * - 포커스 상태에 따른 단축키 활성화/비활성화
 */

import { useEffect, useCallback, useRef } from 'react';
import { useEditorState } from './useEditorState';
import { useHistory } from './useHistory';

// ============================================================================
// 타입 정의
// ============================================================================

export type ShortcutAction =
  | 'undo'
  | 'redo'
  | 'copy'
  | 'cut'
  | 'paste'
  | 'delete'
  | 'duplicate'
  | 'selectAll'
  | 'deselect'
  | 'save'
  | 'toggleEditMode'
  | 'togglePreviewDevice'
  | 'moveUp'
  | 'moveDown'
  | 'moveLeft'
  | 'moveRight'
  | 'escape';

export interface ShortcutDefinition {
  /** 단축키 액션 */
  action: ShortcutAction;
  /** Ctrl/Cmd 키 필요 */
  ctrl?: boolean;
  /** Shift 키 필요 */
  shift?: boolean;
  /** Alt 키 필요 */
  alt?: boolean;
  /** 키 코드 */
  key: string;
  /** 설명 */
  description: string;
  /** 입력 필드에서도 동작 여부 */
  allowInInput?: boolean;
}

export interface UseKeyboardShortcutsOptions {
  /** 단축키 활성화 여부 */
  enabled?: boolean;
  /** 커스텀 단축키 핸들러 */
  onShortcut?: (action: ShortcutAction, event: KeyboardEvent) => boolean;
  /** 입력 필드에서 단축키 비활성화 */
  disableInInputs?: boolean;
}

export interface UseKeyboardShortcutsReturn {
  /** 등록된 단축키 목록 */
  shortcuts: ShortcutDefinition[];
  /** 단축키 활성화 상태 */
  isEnabled: boolean;
  /** 단축키 활성화/비활성화 */
  setEnabled: (enabled: boolean) => void;
  /** 마지막 실행된 단축키 */
  lastAction: ShortcutAction | null;
}

// ============================================================================
// 기본 단축키 정의
// ============================================================================

export const DEFAULT_SHORTCUTS: ShortcutDefinition[] = [
  // Undo/Redo
  { action: 'undo', ctrl: true, key: 'z', description: '실행 취소' },
  { action: 'redo', ctrl: true, shift: true, key: 'z', description: '다시 실행' },
  { action: 'redo', ctrl: true, key: 'y', description: '다시 실행 (대체)' },

  // 복사/붙여넣기
  { action: 'copy', ctrl: true, key: 'c', description: '복사' },
  { action: 'cut', ctrl: true, key: 'x', description: '잘라내기' },
  { action: 'paste', ctrl: true, key: 'v', description: '붙여넣기' },
  { action: 'duplicate', ctrl: true, key: 'd', description: '복제' },

  // 선택
  { action: 'selectAll', ctrl: true, key: 'a', description: '전체 선택' },
  { action: 'deselect', key: 'Escape', description: '선택 해제', allowInInput: true },

  // 삭제
  { action: 'delete', key: 'Delete', description: '삭제' },
  { action: 'delete', key: 'Backspace', description: '삭제 (대체)' },

  // 저장
  { action: 'save', ctrl: true, key: 's', description: '저장' },

  // 모드 전환
  { action: 'toggleEditMode', ctrl: true, key: 'e', description: '편집 모드 전환' },
  { action: 'togglePreviewDevice', ctrl: true, key: 'p', description: '프리뷰 디바이스 전환' },

  // 이동 (Arrow keys)
  { action: 'moveUp', key: 'ArrowUp', description: '위로 이동' },
  { action: 'moveDown', key: 'ArrowDown', description: '아래로 이동' },
  { action: 'moveLeft', key: 'ArrowLeft', description: '트리 접기' },
  { action: 'moveRight', key: 'ArrowRight', description: '트리 펼치기' },

  // ESC
  { action: 'escape', key: 'Escape', description: 'ESC', allowInInput: true },
];

// ============================================================================
// 유틸리티 함수
// ============================================================================

/**
 * 현재 포커스가 입력 필드에 있는지 확인
 */
function isInputFocused(): boolean {
  const activeElement = document.activeElement;
  if (!activeElement) return false;

  const tagName = activeElement.tagName.toLowerCase();
  if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') {
    return true;
  }

  // contenteditable 확인
  if (activeElement.getAttribute('contenteditable') === 'true') {
    return true;
  }

  return false;
}

/**
 * 이벤트가 단축키와 일치하는지 확인
 */
function matchesShortcut(event: KeyboardEvent, shortcut: ShortcutDefinition): boolean {
  const ctrlKey = event.ctrlKey || event.metaKey; // Mac의 Cmd 키 지원

  if (shortcut.ctrl && !ctrlKey) return false;
  if (!shortcut.ctrl && ctrlKey) return false;

  if (shortcut.shift && !event.shiftKey) return false;
  if (!shortcut.shift && event.shiftKey && shortcut.ctrl) return false; // Ctrl+Shift vs Ctrl

  if (shortcut.alt && !event.altKey) return false;
  if (!shortcut.alt && event.altKey) return false;

  // 키 비교 (대소문자 무시)
  return event.key.toLowerCase() === shortcut.key.toLowerCase();
}

/**
 * 단축키 표시 문자열 생성
 */
export function formatShortcut(shortcut: ShortcutDefinition): string {
  const parts: string[] = [];

  if (shortcut.ctrl) {
    parts.push(navigator.platform.includes('Mac') ? '⌘' : 'Ctrl');
  }
  if (shortcut.alt) {
    parts.push(navigator.platform.includes('Mac') ? '⌥' : 'Alt');
  }
  if (shortcut.shift) {
    parts.push('Shift');
  }

  // 키 이름 포맷팅
  let keyName = shortcut.key;
  if (keyName === 'ArrowUp') keyName = '↑';
  else if (keyName === 'ArrowDown') keyName = '↓';
  else if (keyName === 'ArrowLeft') keyName = '←';
  else if (keyName === 'ArrowRight') keyName = '→';
  else if (keyName === 'Escape') keyName = 'Esc';
  else if (keyName === 'Delete') keyName = 'Del';
  else if (keyName === 'Backspace') keyName = '⌫';
  else keyName = keyName.toUpperCase();

  parts.push(keyName);

  return parts.join('+');
}

/**
 * 액션별 단축키 찾기
 */
export function getShortcutForAction(action: ShortcutAction): ShortcutDefinition | undefined {
  return DEFAULT_SHORTCUTS.find((s) => s.action === action);
}

// ============================================================================
// 훅 구현
// ============================================================================

/**
 * 키보드 단축키 훅
 *
 * 편집기에서 사용하는 전역 키보드 단축키를 관리합니다.
 */
export function useKeyboardShortcuts(
  options: UseKeyboardShortcutsOptions = {}
): UseKeyboardShortcutsReturn {
  const {
    enabled = true,
    onShortcut,
    disableInInputs = true,
  } = options;

  const isEnabledRef = useRef(enabled);
  const lastActionRef = useRef<ShortcutAction | null>(null);

  // Zustand 상태 및 액션
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const selectComponent = useEditorState((state) => state.selectComponent);
  const deleteComponent = useEditorState((state) => state.deleteComponent);
  const duplicateComponent = useEditorState((state) => state.duplicateComponent);
  const copyToClipboard = useEditorState((state) => state.copyToClipboard);
  const pasteFromClipboard = useEditorState((state) => state.pasteFromClipboard);
  const clipboard = useEditorState((state) => state.clipboard);
  const setEditMode = useEditorState((state) => state.setEditMode);
  const editMode = useEditorState((state) => state.editMode);
  const setPreviewDevice = useEditorState((state) => state.setPreviewDevice);
  const previewDevice = useEditorState((state) => state.previewDevice);
  const saveLayout = useEditorState((state) => state.saveLayout);
  const layoutData = useEditorState((state) => state.layoutData);
  const toggleTreeNode = useEditorState((state) => state.toggleTreeNode);
  const expandedTreeNodes = useEditorState((state) => state.expandedTreeNodes);

  // 히스토리 훅
  const { undo, redo, canUndo, canRedo } = useHistory();

  // 선택된 컴포넌트 가져오기
  const getSelectedComponent = useCallback(() => {
    if (!layoutData || !selectedComponentId) return null;

    const findComponent = (components: any[]): any => {
      for (const comp of components) {
        if (comp.id === selectedComponentId) return comp;
        if (comp.children) {
          const found = findComponent(comp.children);
          if (found) return found;
        }
      }
      return null;
    };

    return findComponent(layoutData.components || []);
  }, [layoutData, selectedComponentId]);

  // 단축키 핸들러
  const handleKeyDown = useCallback(
    (event: KeyboardEvent) => {
      if (!isEnabledRef.current) return;

      // 입력 필드에서 단축키 비활성화 (특정 단축키 제외)
      const inInput = isInputFocused();

      for (const shortcut of DEFAULT_SHORTCUTS) {
        if (!matchesShortcut(event, shortcut)) continue;

        // 입력 필드에서는 allowInInput이 true인 것만 허용
        if (inInput && disableInInputs && !shortcut.allowInInput) {
          continue;
        }

        // 커스텀 핸들러 먼저 시도
        if (onShortcut && onShortcut(shortcut.action, event)) {
          event.preventDefault();
          lastActionRef.current = shortcut.action;
          return;
        }

        // 기본 동작
        let handled = false;

        switch (shortcut.action) {
          case 'undo':
            if (canUndo) {
              undo();
              handled = true;
            }
            break;

          case 'redo':
            if (canRedo) {
              redo();
              handled = true;
            }
            break;

          case 'copy':
            if (selectedComponentId) {
              const component = getSelectedComponent();
              if (component) {
                copyToClipboard(component);
                handled = true;
              }
            }
            break;

          case 'cut':
            if (selectedComponentId) {
              const component = getSelectedComponent();
              if (component) {
                copyToClipboard(component);
                deleteComponent(selectedComponentId);
                handled = true;
              }
            }
            break;

          case 'paste':
            if (clipboard) {
              pasteFromClipboard(selectedComponentId || undefined, 'after');
              handled = true;
            }
            break;

          case 'duplicate':
            if (selectedComponentId) {
              duplicateComponent(selectedComponentId);
              handled = true;
            }
            break;

          case 'delete':
            if (selectedComponentId && !inInput) {
              deleteComponent(selectedComponentId);
              handled = true;
            }
            break;

          case 'deselect':
          case 'escape':
            if (selectedComponentId) {
              selectComponent(null);
              handled = true;
            }
            break;

          case 'save':
            saveLayout();
            handled = true;
            break;

          case 'toggleEditMode':
            const nextMode = editMode === 'visual' ? 'json' : 'visual';
            setEditMode(nextMode);
            handled = true;
            break;

          case 'togglePreviewDevice':
            const devices: ('desktop' | 'tablet' | 'mobile')[] = ['desktop', 'tablet', 'mobile'];
            const currentIndex = devices.indexOf(previewDevice as any);
            const nextDevice = devices[(currentIndex + 1) % devices.length];
            setPreviewDevice(nextDevice);
            handled = true;
            break;

          case 'moveLeft':
            if (selectedComponentId && expandedTreeNodes.includes(selectedComponentId)) {
              toggleTreeNode(selectedComponentId);
              handled = true;
            }
            break;

          case 'moveRight':
            if (selectedComponentId && !expandedTreeNodes.includes(selectedComponentId)) {
              toggleTreeNode(selectedComponentId);
              handled = true;
            }
            break;

          // moveUp, moveDown은 트리 탐색 - 복잡한 로직 필요
          case 'moveUp':
          case 'moveDown':
            // TODO: 트리 탐색 구현
            break;

          case 'selectAll':
            // 전체 선택은 복잡한 로직 필요
            break;
        }

        if (handled) {
          event.preventDefault();
          event.stopPropagation();
          lastActionRef.current = shortcut.action;
          return;
        }
      }
    },
    [
      canUndo,
      canRedo,
      undo,
      redo,
      selectedComponentId,
      clipboard,
      editMode,
      previewDevice,
      expandedTreeNodes,
      copyToClipboard,
      pasteFromClipboard,
      deleteComponent,
      duplicateComponent,
      selectComponent,
      setEditMode,
      setPreviewDevice,
      saveLayout,
      toggleTreeNode,
      getSelectedComponent,
      onShortcut,
      disableInInputs,
    ]
  );

  // 이벤트 리스너 등록
  useEffect(() => {
    isEnabledRef.current = enabled;

    if (enabled) {
      document.addEventListener('keydown', handleKeyDown);
      return () => {
        document.removeEventListener('keydown', handleKeyDown);
      };
    }
  }, [enabled, handleKeyDown]);

  // 활성화 상태 설정
  const setEnabled = useCallback((value: boolean) => {
    isEnabledRef.current = value;
  }, []);

  return {
    shortcuts: DEFAULT_SHORTCUTS,
    isEnabled: isEnabledRef.current,
    setEnabled,
    lastAction: lastActionRef.current,
  };
}

// ============================================================================
// Export
// ============================================================================

export default useKeyboardShortcuts;
