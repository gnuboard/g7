/**
 * editorShortcuts.test.ts — 단축키 맵 SSoT + 매칭
 *
 * @scenario shortcut_keymap
 * @effects shortcut_keymap_ssot_matches_event_combo, shortcut_redo_has_two_combos, shortcut_format_combo_platform_aware, shortcut_escape_matches_deselect_and_exit
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import {
  EDITOR_SHORTCUTS,
  eventCombo,
  matchShortcut,
  formatCombo,
  SHORTCUT_GROUP_ORDER,
} from '../../spec/editorShortcuts';

function ev(key: string, mods: Partial<{ ctrl: boolean; meta: boolean; shift: boolean; alt: boolean }> = {}) {
  return eventCombo({ key, ctrlKey: !!mods.ctrl, metaKey: !!mods.meta, shiftKey: !!mods.shift, altKey: !!mods.alt });
}

describe('editorShortcuts — 매칭', () => {
  it('mod+c → copy, mod+x → cut, mod+v → paste', () => {
    expect(matchShortcut(ev('c', { ctrl: true }))?.id).toBe('copy');
    expect(matchShortcut(ev('x', { ctrl: true }))?.id).toBe('cut');
    expect(matchShortcut(ev('v', { ctrl: true }))?.id).toBe('paste');
    // Mac meta 도 동일.
    expect(matchShortcut(ev('c', { meta: true }))?.id).toBe('copy');
  });

  it('mod+z → undo, mod+shift+z / mod+y → redo (2 조합)', () => {
    expect(matchShortcut(ev('z', { ctrl: true }))?.id).toBe('undo');
    expect(matchShortcut(ev('z', { ctrl: true, shift: true }))?.id).toBe('redo');
    expect(matchShortcut(ev('y', { ctrl: true }))?.id).toBe('redo');
  });

  it('Enter → openProps, Delete/Backspace → delete, ArrowUp → selectParent', () => {
    expect(matchShortcut(ev('Enter'))?.id).toBe('openProps');
    expect(matchShortcut(ev('Delete'))?.id).toBe('delete');
    expect(matchShortcut(ev('Backspace'))?.id).toBe('delete');
    expect(matchShortcut(ev('ArrowUp'))?.id).toBe('selectParent');
  });

  it('Escape → deselect(정의 순서 우선, exit 보다 먼저)', () => {
    // 정의 배열에서 deselect 가 exit 보다 앞 → matchShortcut 은 deselect 반환.
    // (실제 우선순위는 useEditorShortcuts 가 hasSelection 으로 재판정.)
    expect(matchShortcut(ev('Escape'))?.id).toBe('deselect');
  });

  it('save mod+s · editCode mod+e · preview mod+p · addElement mod+/ · translations mod+l · reset mod+shift+r · help ?', () => {
    expect(matchShortcut(ev('s', { ctrl: true }))?.id).toBe('save');
    expect(matchShortcut(ev('e', { ctrl: true }))?.id).toBe('editCode');
    expect(matchShortcut(ev('p', { ctrl: true }))?.id).toBe('preview');
    expect(matchShortcut(ev('/', { ctrl: true }))?.id).toBe('addElement');
    expect(matchShortcut(ev('l', { ctrl: true }))?.id).toBe('translations');
    expect(matchShortcut(ev('r', { ctrl: true, shift: true }))?.id).toBe('reset');
    expect(matchShortcut(ev('?'))?.id).toBe('help');
  });

  it('수식어 불일치는 매칭 안 됨(mod 없는 c, shift 만 다른 조합)', () => {
    expect(matchShortcut(ev('c'))).toBeNull(); // plain c
    expect(matchShortcut(ev('s'))).toBeNull(); // plain s
  });
});

describe('editorShortcuts — 표기', () => {
  it('formatCombo: Win=Ctrl, Mac=⌘', () => {
    const undo = EDITOR_SHORTCUTS.find((s) => s.id === 'undo')!;
    expect(formatCombo(undo.combos[0]!, false)).toBe('Ctrl + Z');
    expect(formatCombo(undo.combos[0]!, true)).toBe('⌘ + Z');
  });

  it('formatCombo: 특수키 글리프(Enter/Esc/Delete/↑)', () => {
    expect(formatCombo({ key: 'enter' }, false)).toBe('Enter');
    expect(formatCombo({ key: 'escape' }, false)).toBe('Esc');
    expect(formatCombo({ key: 'arrowup' }, false)).toBe('↑');
  });

  it('모든 그룹이 SHORTCUT_GROUP_ORDER 에 존재', () => {
    for (const s of EDITOR_SHORTCUTS) {
      expect(SHORTCUT_GROUP_ORDER).toContain(s.group);
    }
  });
});
