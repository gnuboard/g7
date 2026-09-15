/**
 * codeEditorShortcuts.ts — 관리자 **코드 편집기** 단축키 맵 SSoT
 *
 * 단축키의 단일 정의 소스. 매칭({@link matchCodeShortcut})과 도움말 오버레이가 같은 배열을
 * 읽으므로 **단축키 추가 = 이 파일 한 곳 수정**이다.
 *
 * ## 왜 코어의 맵을 import 하지 않는가
 *
 * 이 템플릿은 독립 IIFE 번들이라(`vite.config.ts` `formats:['iife']`) 코어(`resources/js`)에서
 * import 할 수 없다. 구조는 코어의
 * `resources/js/core/template-engine/layout-editor/spec/editorShortcuts.ts` 를 그대로 따르되
 * 정의는 이 화면의 것이다 — 두 편집기는 다루는 대상이 다르므로(위지윅은 노드, 여기는 문서·파일)
 * 같은 키가 같은 뜻일 수 없다.
 *
 * ## 브라우저·OS 가 이미 쓰는 조합을 피한 자리
 *
 * - `mod+w` — 브라우저 탭을 닫는다. 가로챌 수 없으므로 탭 닫기는 `mod+shift+w` 로 둔다.
 * - `mod+tab` — OS 예약. 탭 전환은 `mod+alt+arrowleft/right`.
 * - `mod+n` — 새 창을 여는 브라우저가 있어 `mod+alt+n` 을 **대체 조합으로 함께** 등록한다.
 * - `mod+f` — 여기 정의에 없다. Monaco 의 파일 내 찾기를 그대로 쓰는 것이 더 낫기 때문이다
 *   (도움말에는 보이되 우리가 가로채지 않는다 — {@link EDITOR_OWNED_SHORTCUTS}).
 */

/** 단축키 액션 식별자 (핸들러 디스패치 키). */
export type CodeShortcutActionId =
  | 'save'
  | 'saveAll'
  | 'preview'
  | 'versionHistory'
  | 'exit'
  | 'newFile'
  | 'quickOpen'
  | 'searchContent'
  | 'prevTab'
  | 'nextTab'
  | 'closeTab'
  | 'toggleView'
  | 'toggleWordWrap'
  | 'fontSizeUp'
  | 'fontSizeDown'
  | 'help';

/** 단축키 그룹 (도움말 오버레이 분류). */
export type CodeShortcutGroup = 'document' | 'file' | 'view' | 'editor';

/** 키 조합 1건 — 같은 액션에 복수 조합을 허용한다(새 파일 = `mod+n` 또는 `mod+alt+n`). */
export interface CodeKeyCombo {
  /** 단일 key — `e.key` 를 소문자로 정규화한 값 (`s`·`escape`·`arrowleft`·`?` 등) */
  key: string;
  /** Ctrl(Win/Linux) 또는 ⌘(Mac) 필요 여부 */
  mod?: boolean;
  shift?: boolean;
  alt?: boolean;
}

/** 단축키 정의 1건. */
export interface CodeShortcutSpec {
  id: CodeShortcutActionId;
  group: CodeShortcutGroup;
  /** 매칭 조합 (복수 허용) */
  combos: CodeKeyCombo[];
  /** 도움말 라벨 i18n 키 */
  labelKey: string;
  /**
   * Monaco 가 선점하는 조합 — 창 리스너만으로는 편집기에 포커스가 있을 때 동작이 갈린다.
   * `CodeWorkspace` 가 이 표시를 보고 `editor.addCommand()` 로 **편집기 안에서도** 같은 동작을 건다.
   */
  rebindInEditor?: boolean;
  /**
   * 편집기가 자기 동작으로 처리하는 조합 — 우리는 가로채지 않고 도움말에만 싣는다.
   * (`mod+f` 파일 내 찾기)
   */
  editorOwned?: boolean;
}

/** 단축키 맵 SSoT — 도움말 표시 순서 = 그룹별 정의 순서. */
export const CODE_EDITOR_SHORTCUTS: CodeShortcutSpec[] = [
  // 문서
  { id: 'save', group: 'document', combos: [{ key: 's', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.save' },
  { id: 'saveAll', group: 'document', combos: [{ key: 's', mod: true, alt: true }], labelKey: 'admin.templates.layout_edit.shortcuts.save_all' },
  { id: 'preview', group: 'document', combos: [{ key: 'p', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.preview', rebindInEditor: true },
  { id: 'versionHistory', group: 'document', combos: [{ key: 'h', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.version_history' },
  { id: 'exit', group: 'document', combos: [{ key: 'escape' }], labelKey: 'admin.templates.layout_edit.shortcuts.exit' },

  // 파일
  { id: 'newFile', group: 'file', combos: [{ key: 'n', mod: true }, { key: 'n', mod: true, alt: true }], labelKey: 'admin.templates.layout_edit.shortcuts.new_file' },
  { id: 'quickOpen', group: 'file', combos: [{ key: 'k', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.quick_open', rebindInEditor: true },
  { id: 'searchContent', group: 'file', combos: [{ key: 'f', mod: true, shift: true }], labelKey: 'admin.templates.layout_edit.shortcuts.search_content' },
  { id: 'prevTab', group: 'file', combos: [{ key: 'arrowleft', mod: true, alt: true }], labelKey: 'admin.templates.layout_edit.shortcuts.prev_tab' },
  { id: 'nextTab', group: 'file', combos: [{ key: 'arrowright', mod: true, alt: true }], labelKey: 'admin.templates.layout_edit.shortcuts.next_tab' },
  { id: 'closeTab', group: 'file', combos: [{ key: 'w', mod: true, shift: true }], labelKey: 'admin.templates.layout_edit.shortcuts.close_tab' },

  // 보기
  { id: 'toggleView', group: 'view', combos: [{ key: '\\', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.toggle_view' },
  { id: 'help', group: 'view', combos: [{ key: '?' }], labelKey: 'admin.templates.layout_edit.shortcuts.help' },

  // 편집기
  { id: 'toggleWordWrap', group: 'editor', combos: [{ key: 'z', alt: true }], labelKey: 'admin.templates.layout_edit.shortcuts.toggle_word_wrap', rebindInEditor: true },
  // 글자 크기 12~20px (§7.6). `Ctrl + +` 는 자판에 따라 `=`(Shift 없이)·`+`(Shift 또는 숫자패드)로 오므로 셋을 같은 액션에 건다.
  // 브라우저 확대(Ctrl+=/-)를 이 화면에서 대신한다 — 편집기 글자만 키우는 것이 계획의 요구다.
  { id: 'fontSizeUp', group: 'editor', combos: [{ key: '=', mod: true }, { key: '+', mod: true, shift: true }, { key: '+', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.font_size_up', rebindInEditor: true },
  { id: 'fontSizeDown', group: 'editor', combos: [{ key: '-', mod: true }], labelKey: 'admin.templates.layout_edit.shortcuts.font_size_down', rebindInEditor: true },
];

/**
 * 편집기가 직접 처리하는 조합 — 도움말에만 싣고 우리는 가로채지 않는다.
 *
 * 별도 배열로 두는 이유: {@link CODE_EDITOR_SHORTCUTS} 에 넣으면 매칭 루프가 이 조합도
 * `preventDefault` 대상으로 삼을 위험이 생긴다. "잡지 않는다" 는 사실을 타입이 아니라
 * **다른 목록에 있다는 사실**로 보장한다.
 */
export const EDITOR_OWNED_SHORTCUTS: Array<{ combo: CodeKeyCombo; group: CodeShortcutGroup; labelKey: string }> = [
  { combo: { key: 'f', mod: true }, group: 'editor', labelKey: 'admin.templates.layout_edit.shortcuts.find_in_file' },
];

/** 그룹 표시 순서 + 라벨 키. */
export const CODE_SHORTCUT_GROUP_ORDER: CodeShortcutGroup[] = ['document', 'file', 'view', 'editor'];

export const CODE_SHORTCUT_GROUP_LABEL: Record<CodeShortcutGroup, string> = {
  document: 'admin.templates.layout_edit.shortcuts.group_document',
  file: 'admin.templates.layout_edit.shortcuts.group_file',
  view: 'admin.templates.layout_edit.shortcuts.group_view',
  editor: 'admin.templates.layout_edit.shortcuts.group_editor',
};

/**
 * 키 이벤트를 정규화 조합으로 옮깁니다 (매칭·표시 공용).
 *
 * @param event 키 이벤트 (필요한 네 필드만 받는다 — 테스트가 가짜 객체를 넘길 수 있게)
 * @returns 정규화된 조합
 */
export function codeEventCombo(event: {
  key: string;
  ctrlKey: boolean;
  metaKey: boolean;
  shiftKey: boolean;
  altKey: boolean;
}): CodeKeyCombo {
  return {
    key: (event.key || '').toLowerCase(),
    mod: event.ctrlKey || event.metaKey,
    shift: event.shiftKey,
    alt: event.altKey,
  };
}

/**
 * 한 조합이 이벤트와 일치하는지 판정합니다.
 *
 * 네 자리를 모두 불리언으로 정규화해 비교한다 — `undefined` 와 `false` 를 같게 보지 않으면
 * `{key:'s', mod:true}` 가 `Ctrl+Shift+S` 에도 걸린다.
 *
 * @param spec 정의된 조합
 * @param event 눌린 조합
 * @returns 일치하면 true
 */
function comboMatches(spec: CodeKeyCombo, event: CodeKeyCombo): boolean {
  return (
    spec.key === event.key &&
    !!spec.mod === !!event.mod &&
    !!spec.shift === !!event.shift &&
    !!spec.alt === !!event.alt
  );
}

/**
 * 이벤트에 일치하는 첫 단축키 정의를 돌려줍니다.
 *
 * @param event 정규화된 조합
 * @returns 일치하는 정의 (없으면 null)
 */
export function matchCodeShortcut(event: CodeKeyCombo): CodeShortcutSpec | null {
  for (const spec of CODE_EDITOR_SHORTCUTS) {
    if (spec.combos.some((combo) => comboMatches(combo, event))) {
      return spec;
    }
  }

  return null;
}

/**
 * 이 플랫폼이 Mac 인지 판정합니다 (표기를 ⌘ 로 바꿀지 결정).
 *
 * `navigator.platform` 은 폐기 예정이지만 `userAgentData` 가 없는 브라우저가 아직 많아
 * 둘을 순서대로 본다. 판정이 틀려도 **동작은 같고 표기만 달라진다** — `mod` 는 Ctrl·⌘ 를
 * 모두 받기 때문이다.
 *
 * @returns Mac 이면 true
 */
export function isMacPlatform(): boolean {
  if (typeof navigator === 'undefined') {
    return false;
  }

  const platform =
    (navigator as unknown as { userAgentData?: { platform?: string } }).userAgentData?.platform ??
    navigator.platform ??
    '';

  return /mac/i.test(platform);
}

/** 키 이름 → 사람이 읽는 표기. */
const KEY_LABELS: Record<string, string> = {
  arrowup: '↑',
  arrowdown: '↓',
  arrowleft: '←',
  arrowright: '→',
  enter: 'Enter',
  escape: 'Esc',
  delete: 'Delete',
  backspace: 'Backspace',
  ' ': 'Space',
  '\\': '\\',
};

/**
 * 조합을 사람이 읽는 표기로 옮깁니다 (도움말 오버레이).
 *
 * @param combo 조합
 * @param isMac Mac 표기를 쓸지
 * @returns 표기 문자열 (예: `Ctrl + Shift + W`)
 */
export function formatCodeCombo(combo: CodeKeyCombo, isMac: boolean): string {
  const parts: string[] = [];

  if (combo.mod) {
    parts.push(isMac ? '⌘' : 'Ctrl');
  }

  if (combo.shift) {
    parts.push(isMac ? '⇧' : 'Shift');
  }

  if (combo.alt) {
    parts.push(isMac ? '⌥' : 'Alt');
  }

  parts.push(KEY_LABELS[combo.key] ?? (combo.key.length === 1 ? combo.key.toUpperCase() : combo.key));

  return parts.join(' + ');
}
