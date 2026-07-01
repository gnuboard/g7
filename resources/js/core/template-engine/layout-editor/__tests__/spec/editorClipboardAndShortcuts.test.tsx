/**
 * editorClipboardAndShortcuts.test.tsx — 클립보드 버퍼 + 단축키 디스패처 + 단축키 맵 모달
 *
 *
 * @scenario shortcut_clipboard + shortcut_dispatch + shortcut_help_modal
 * @effects clipboard_session_roundtrip_strips_internal_meta, clipboard_read_returns_fresh_copy, shortcut_dispatch_guards_input_and_modal, shortcut_escape_branches_deselect_vs_exit_by_selection, shortcut_requires_selection_guard, shortcut_help_modal_lists_all_groups_from_ssot, paste_across_layouts_via_session_storage
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, renderHook } from '@testing-library/react';
import { writeClipboard, readClipboard, hasClipboard, clearClipboard } from '../../utils/editorClipboard';
import { useEditorShortcuts } from '../../hooks/useEditorShortcuts';
import { ShortcutHelpModal } from '../../components/ShortcutHelpModal';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string) => k;

afterEach(() => { cleanup(); clearClipboard(); });
beforeEach(() => clearClipboard());

describe('editorClipboard — sessionStorage round-trip', () => {
  it('write→read 복원 + 내부 메타(__source) 제거', () => {
    const node: EditorNode = { type: 'basic', name: 'Div', __source: { kind: 'extension' } as never, children: [{ type: 'basic', name: 'Span', text: 'x', __source: {} as never }] };
    expect(writeClipboard(node)).toBe(true);
    expect(hasClipboard()).toBe(true);
    const out = readClipboard()!;
    expect(out.name).toBe('Div');
    expect((out as Record<string, unknown>).__source).toBeUndefined();
    expect(((out.children as EditorNode[])[0] as Record<string, unknown>).__source).toBeUndefined();
    expect((out.children as EditorNode[])[0].text).toBe('x');
  });

  it('read 는 매번 새 복제본(연속 붙여넣기 안전)', () => {
    writeClipboard({ type: 'basic', name: 'Div' });
    const a = readClipboard();
    const b = readClipboard();
    expect(a).not.toBe(b); // 다른 객체 참조
    expect(a).toEqual(b);
  });

  it('빈 클립보드 → null', () => {
    expect(readClipboard()).toBeNull();
    expect(hasClipboard()).toBe(false);
  });

  it('다른 레이아웃 시뮬레이션 — write 후 (네비게이션 가정) read 가능', () => {
    writeClipboard({ type: 'basic', name: 'Button', text: '버튼' });
    // sessionStorage 는 네비게이션/리렌더에 무관하게 유지 → read 성공.
    expect(readClipboard()?.name).toBe('Button');
  });
});

describe('useEditorShortcuts — 디스패치/가드', () => {
  function setup(handlers: Record<string, () => void>, hasSelection: boolean) {
    return renderHook(() => useEditorShortcuts({ handlers, hasSelection }));
  }
  function key(k: string, mods: Partial<{ ctrl: boolean; shift: boolean }> = {}, target?: EventTarget) {
    const e = new KeyboardEvent('keydown', { key: k, ctrlKey: !!mods.ctrl, shiftKey: !!mods.shift, bubbles: true, cancelable: true });
    if (target) Object.defineProperty(e, 'target', { value: target });
    window.dispatchEvent(e);
    return e;
  }

  it('mod+c → copy 핸들러 호출', () => {
    const copy = vi.fn();
    setup({ copy }, true);
    key('c', { ctrl: true });
    expect(copy).toHaveBeenCalledTimes(1);
  });

  it('입력칸 포커스 시 가로채지 않음', () => {
    const copy = vi.fn();
    setup({ copy }, true);
    const input = document.createElement('input');
    document.body.appendChild(input);
    key('c', { ctrl: true }, input);
    expect(copy).not.toHaveBeenCalled();
    input.remove();
  });

  it('모달 열림 시 가로채지 않음', () => {
    const copy = vi.fn();
    setup({ copy }, true);
    const backdrop = document.createElement('div');
    backdrop.className = 'g7le-modal-backdrop';
    document.body.appendChild(backdrop);
    key('c', { ctrl: true });
    expect(copy).not.toHaveBeenCalled();
    backdrop.remove();
  });

  it('requiresSelection: 선택 없으면 copy 무시', () => {
    const copy = vi.fn();
    setup({ copy }, false);
    key('c', { ctrl: true });
    expect(copy).not.toHaveBeenCalled();
  });

  it('Escape: 선택 있으면 deselect, 없으면 exit', () => {
    const deselect = vi.fn();
    const { unmount } = setup({ deselect }, true);
    key('Escape');
    expect(deselect).toHaveBeenCalledTimes(1);
    unmount();
    const exit = vi.fn();
    setup({ exit }, false);
    key('Escape');
    expect(exit).toHaveBeenCalledTimes(1);
  });

  it('paste 는 선택 없어도 동작(requiresSelection 아님)', () => {
    const paste = vi.fn();
    setup({ paste }, false);
    key('v', { ctrl: true });
    expect(paste).toHaveBeenCalledTimes(1);
  });
});

describe('ShortcutHelpModal — SSoT 기반 렌더', () => {
  it('모든 그룹 + 대표 액션 행 렌더(키맵 SSoT)', () => {
    render(<ShortcutHelpModal t={t} onClose={vi.fn()} isMac={false} />);
    expect(screen.getByTestId('g7le-shortcut-group-clipboard')).toBeTruthy();
    expect(screen.getByTestId('g7le-shortcut-group-element')).toBeTruthy();
    expect(screen.getByTestId('g7le-shortcut-group-document')).toBeTruthy();
    expect(screen.getByTestId('g7le-shortcut-row-copy')).toBeTruthy();
    expect(screen.getByTestId('g7le-shortcut-row-paste')).toBeTruthy();
    expect(screen.getByTestId('g7le-shortcut-row-save')).toBeTruthy();
  });

  it('닫기 버튼 → onClose', () => {
    const onClose = vi.fn();
    render(<ShortcutHelpModal t={t} onClose={onClose} isMac={false} />);
    fireEvent.click(screen.getByTestId('g7le-shortcut-help-close'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('Mac 표기 ⌘ 적용', () => {
    render(<ShortcutHelpModal t={t} onClose={vi.fn()} isMac={true} />);
    const row = screen.getByTestId('g7le-shortcut-row-save');
    expect(row.textContent).toContain('⌘');
  });
});
