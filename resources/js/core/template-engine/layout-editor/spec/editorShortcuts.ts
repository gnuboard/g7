/**
 * editorShortcuts.ts — 레이아웃 편집기 단축키 맵 SSoT
 *
 * 단축키의 **단일 정의 소스**. `useEditorShortcuts`(키 매칭·디스패치)와 `ShortcutHelpModal`
 * (단축키 맵 표시)이 같은 정의를 읽어 항상 일치한다(키 추가 = 한 곳 수정).
 *
 * 키 표기: `mod` = Ctrl(Win/Linux) 또는 ⌘(Mac). `combo` 는 소문자 정규화 토큰 집합으로
 * 매칭한다(`mod`/`shift`/`alt` + 단일 key). 표시는 플랫폼에 맞춰 `mod`→Ctrl/⌘.
 *
 * 표준 키 바인딩:
 *  복사 mod+c · 잘라내기 mod+x · 붙여넣기 mod+v(다른 레이아웃 가능) · 속성 Enter ·
 *  삭제 Delete/Backspace · 뒤로 mod+z · 앞으로 mod+shift+z · 코드편집 mod+e ·
 *  미리보기 mod+p · 요소추가 mod+/ · 초기화 mod+shift+r · 다국어 mod+l ·
 *  저장 mod+s · 나가기 Escape(선택 없을 때) · 부모선택 ArrowUp · 단축키맵 ?
 *
 * @since engine-v1.50.0
 */

/** 단축키 액션 식별자(핸들러 디스패치 키). */
export type ShortcutActionId =
  | 'copy'
  | 'cut'
  | 'paste'
  | 'openProps'
  | 'delete'
  | 'undo'
  | 'redo'
  | 'editCode'
  | 'preview'
  | 'addElement'
  | 'reset'
  | 'translations'
  | 'save'
  | 'exit'
  | 'selectParent'
  | 'deselect'
  | 'help';

/** 단축키 그룹(맵 모달 분류). */
export type ShortcutGroup = 'clipboard' | 'element' | 'history' | 'view' | 'document';

/** 키 조합 1건 — 같은 액션에 복수 조합 허용(예 redo = mod+shift+z 또는 mod+y). */
export interface KeyCombo {
  /** 단일 key (`e.key` 소문자 정규화 — `c`/`enter`/`delete`/`arrowup`/`?` 등). */
  key: string;
  /** Ctrl/⌘ 필요 여부. */
  mod?: boolean;
  shift?: boolean;
  alt?: boolean;
}

/** 단축키 정의 1건. */
export interface ShortcutSpec {
  id: ShortcutActionId;
  group: ShortcutGroup;
  /** 매칭 조합(복수 허용). */
  combos: KeyCombo[];
  /** 맵 모달 라벨 i18n 키(`layout_editor.shortcuts.action.*`). */
  labelKey: string;
  /**
   * 선택 노드가 있어야만 동작하는 액션(copy/cut/delete/openProps/selectParent).
   * deselect(Escape)·document 액션은 선택 불요.
   */
  requiresSelection?: boolean;
}

/** 단축키 맵 SSoT — 표시 순서 = 그룹별 정의 순서. */
export const EDITOR_SHORTCUTS: ShortcutSpec[] = [
  // 클립보드
  { id: 'copy', group: 'clipboard', combos: [{ key: 'c', mod: true }], labelKey: 'layout_editor.shortcuts.action.copy', requiresSelection: true },
  { id: 'cut', group: 'clipboard', combos: [{ key: 'x', mod: true }], labelKey: 'layout_editor.shortcuts.action.cut', requiresSelection: true },
  { id: 'paste', group: 'clipboard', combos: [{ key: 'v', mod: true }], labelKey: 'layout_editor.shortcuts.action.paste' },
  // 요소
  { id: 'openProps', group: 'element', combos: [{ key: 'enter' }], labelKey: 'layout_editor.shortcuts.action.open_props', requiresSelection: true },
  { id: 'delete', group: 'element', combos: [{ key: 'delete' }, { key: 'backspace' }], labelKey: 'layout_editor.shortcuts.action.delete', requiresSelection: true },
  { id: 'selectParent', group: 'element', combos: [{ key: 'arrowup' }], labelKey: 'layout_editor.shortcuts.action.select_parent', requiresSelection: true },
  { id: 'deselect', group: 'element', combos: [{ key: 'escape' }], labelKey: 'layout_editor.shortcuts.action.deselect' },
  { id: 'addElement', group: 'element', combos: [{ key: '/', mod: true }], labelKey: 'layout_editor.shortcuts.action.add_element' },
  // 히스토리
  { id: 'undo', group: 'history', combos: [{ key: 'z', mod: true }], labelKey: 'layout_editor.shortcuts.action.undo' },
  { id: 'redo', group: 'history', combos: [{ key: 'z', mod: true, shift: true }, { key: 'y', mod: true }], labelKey: 'layout_editor.shortcuts.action.redo' },
  // 보기/도구
  { id: 'editCode', group: 'view', combos: [{ key: 'e', mod: true }], labelKey: 'layout_editor.shortcuts.action.edit_code' },
  { id: 'preview', group: 'view', combos: [{ key: 'p', mod: true }], labelKey: 'layout_editor.shortcuts.action.preview' },
  { id: 'translations', group: 'view', combos: [{ key: 'l', mod: true }], labelKey: 'layout_editor.shortcuts.action.translations' },
  { id: 'help', group: 'view', combos: [{ key: '?' }], labelKey: 'layout_editor.shortcuts.action.help' },
  // 문서
  { id: 'reset', group: 'document', combos: [{ key: 'r', mod: true, shift: true }], labelKey: 'layout_editor.shortcuts.action.reset' },
  { id: 'save', group: 'document', combos: [{ key: 's', mod: true }], labelKey: 'layout_editor.shortcuts.action.save' },
  { id: 'exit', group: 'document', combos: [{ key: 'escape' }], labelKey: 'layout_editor.shortcuts.action.exit' },
];

/** 이벤트 → 정규화 조합 토큰(매칭/표시 공용). */
export function eventCombo(e: { key: string; ctrlKey: boolean; metaKey: boolean; shiftKey: boolean; altKey: boolean }): KeyCombo {
  return {
    key: (e.key || '').toLowerCase(),
    mod: e.ctrlKey || e.metaKey,
    shift: e.shiftKey,
    alt: e.altKey,
  };
}

/** 한 조합이 이벤트와 일치하는지(불리언 정규화 — undefined=false). */
function comboMatches(spec: KeyCombo, ev: KeyCombo): boolean {
  return (
    spec.key === ev.key &&
    !!spec.mod === !!ev.mod &&
    !!spec.shift === !!ev.shift &&
    !!spec.alt === !!ev.alt
  );
}

/** 이벤트에 일치하는 첫 단축키 정의 반환(없으면 null). 정의 순서 우선. */
export function matchShortcut(ev: KeyCombo): ShortcutSpec | null {
  for (const s of EDITOR_SHORTCUTS) {
    if (s.combos.some((c) => comboMatches(c, ev))) return s;
  }
  return null;
}

/** 조합을 사람이 읽는 표기로(맵 모달). `mod`→플랫폼별 Ctrl/⌘. */
export function formatCombo(c: KeyCombo, isMac: boolean): string {
  const parts: string[] = [];
  if (c.mod) parts.push(isMac ? '⌘' : 'Ctrl');
  if (c.shift) parts.push(isMac ? '⇧' : 'Shift');
  if (c.alt) parts.push(isMac ? '⌥' : 'Alt');
  parts.push(formatKey(c.key));
  return parts.join(' + ');
}

function formatKey(key: string): string {
  const map: Record<string, string> = {
    arrowup: '↑', arrowdown: '↓', arrowleft: '←', arrowright: '→',
    enter: 'Enter', escape: 'Esc', delete: 'Delete', backspace: 'Backspace',
    ' ': 'Space',
  };
  return map[key] ?? (key.length === 1 ? key.toUpperCase() : key);
}

/** 그룹 표시 순서 + 라벨 키. */
export const SHORTCUT_GROUP_ORDER: ShortcutGroup[] = ['clipboard', 'element', 'history', 'view', 'document'];
export const SHORTCUT_GROUP_LABEL: Record<ShortcutGroup, string> = {
  clipboard: 'layout_editor.shortcuts.group.clipboard',
  element: 'layout_editor.shortcuts.group.element',
  history: 'layout_editor.shortcuts.group.history',
  view: 'layout_editor.shortcuts.group.view',
  document: 'layout_editor.shortcuts.group.document',
};
