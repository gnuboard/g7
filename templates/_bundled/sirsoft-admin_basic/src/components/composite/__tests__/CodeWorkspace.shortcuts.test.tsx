import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { CodeWorkspace } from '../CodeWorkspace';
import {
  CODE_EDITOR_SHORTCUTS,
  EDITOR_OWNED_SHORTCUTS,
  codeEventCombo,
  formatCodeCombo,
  matchCodeShortcut,
} from '../../../spec/codeEditorShortcuts';

/**
 * 단축키 — SSoT 와 그 발화 규칙.
 *
 * 단축키 정의는 `src/spec/codeEditorShortcuts.ts` 한 곳이고, 매칭과 도움말이 같은 배열을 읽는다.
 * 여기서 잠그는 것은 **정의의 존재**가 아니라 **오발동하지 않는다는 성질**이다 — 검색창에 `?` 를
 * 치면 글자가 들어가야지 도움말이 열려서는 안 된다.
 */

vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value, onChange }: any) => (
    <textarea data-testid="editor-textarea" value={value ?? ''} onChange={(event) => onChange?.(event.target.value)} />
  ),
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

beforeEach(() => {
  window.localStorage.clear();
});

describe('단축키 SSoT', () => {
  /** @effects code_shortcuts_have_unique_combos */
  it('같은 조합이 두 액션에 걸리지 않는다', () => {
    const seen = new Set<string>();

    for (const spec of CODE_EDITOR_SHORTCUTS) {
      for (const combo of spec.combos) {
        const token = `${combo.mod ? 'mod+' : ''}${combo.shift ? 'shift+' : ''}${combo.alt ? 'alt+' : ''}${combo.key}`;

        expect(seen.has(token), `중복 조합: ${token}`).toBe(false);
        seen.add(token);
      }
    }
  });

  /**
   * `mod+w` 는 브라우저 탭을 닫는다 — 가로챌 수 없으므로 **등록하지 않는다**.
   *
   * 등록해 두면 사용자는 그 키가 동작할 것이라 믿고 누르고, 편집 중인 탭이 통째로 닫힌다.
   *
   * @effects code_shortcuts_avoid_browser_reserved_combos
   */
  it('브라우저·OS 가 선점한 조합은 등록하지 않는다', () => {
    const combos = CODE_EDITOR_SHORTCUTS.flatMap((spec) => spec.combos);

    expect(combos.some((c) => c.mod && !c.shift && !c.alt && c.key === 'w')).toBe(false);
    expect(combos.some((c) => c.key === 'tab')).toBe(false);
  });

  /** @effects code_shortcuts_register_alternate_for_new_file */
  it('새 파일은 대체 조합을 함께 등록한다 — mod+n 은 브라우저가 새 창을 열 수 있다', () => {
    const spec = CODE_EDITOR_SHORTCUTS.find((s) => s.id === 'newFile');

    expect(spec?.combos).toHaveLength(2);
    expect(spec?.combos.some((c) => c.alt)).toBe(true);
  });

  /** @effects code_shortcuts_leave_find_in_file_to_the_editor */
  it('파일 내 찾기(mod+f)는 우리가 잡지 않고 도움말에만 싣는다', () => {
    expect(CODE_EDITOR_SHORTCUTS.some((s) => s.combos.some((c) => c.mod && !c.shift && c.key === 'f'))).toBe(false);
    expect(EDITOR_OWNED_SHORTCUTS.some((entry) => entry.combo.mod && entry.combo.key === 'f')).toBe(true);
  });

  /** @effects code_shortcuts_match_exact_modifier_sets */
  it('조합 매칭은 네 자리를 모두 본다', () => {
    const save = matchCodeShortcut(codeEventCombo({ key: 's', ctrlKey: true, metaKey: false, shiftKey: false, altKey: false }));
    const saveAll = matchCodeShortcut(codeEventCombo({ key: 's', ctrlKey: true, metaKey: false, shiftKey: false, altKey: true }));
    const shifted = matchCodeShortcut(codeEventCombo({ key: 's', ctrlKey: true, metaKey: false, shiftKey: true, altKey: false }));

    expect(save?.id).toBe('save');
    expect(saveAll?.id).toBe('saveAll');
    // Ctrl+Shift+S 는 정의에 없다 — 느슨하게 매칭하면 저장이 엉뚱한 조합에서도 돈다.
    expect(shifted).toBeNull();
  });

  /** @effects code_shortcuts_format_per_platform */
  it('표기는 플랫폼을 따른다', () => {
    const combo = { key: 's', mod: true };

    expect(formatCodeCombo(combo, false)).toBe('Ctrl + S');
    expect(formatCodeCombo(combo, true)).toBe('⌘ + S');
  });
});

describe('CodeWorkspace — 단축키 발화', () => {
  /** @effects code_workspace_ctrl_s_saves */
  it('Ctrl+S 는 저장을 부르고 브라우저 기본 동작을 막는다', () => {
    const onSave = vi.fn();

    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} onSave={onSave} />);

    const event = new KeyboardEvent('keydown', { key: 's', ctrlKey: true, cancelable: true, bubbles: true });
    act(() => {
      window.dispatchEvent(event);
    });

    expect(onSave).toHaveBeenCalledTimes(1);
    expect(event.defaultPrevented).toBe(true);
  });

  /** @effects code_workspace_question_mark_opens_help */
  it('? 로 도움말이 열린다', () => {
    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} />);

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: '?', bubbles: true }));
    });

    expect(screen.getByTestId('shortcut-help')).toBeInTheDocument();
  });

  /**
   * 입력 중 오발동 금지.
   *
   * 검색창에 `?` 를 치면 그 글자가 들어가야 한다. `mod` 없는 단일 키를 입력 자리에서 가로채면
   * 사용자는 그 글자를 영영 입력할 수 없다.
   *
   * @effects code_workspace_ignores_single_keys_while_typing
   */
  it('입력 자리에서는 mod 없는 단일 키를 무시한다', () => {
    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} />);

    const input = document.createElement('input');
    document.body.appendChild(input);
    input.focus();

    try {
      act(() => {
        input.dispatchEvent(new KeyboardEvent('keydown', { key: '?', bubbles: true }));
      });

      expect(screen.queryByTestId('shortcut-help')).not.toBeInTheDocument();
    } finally {
      input.remove();
    }
  });

  /** @effects code_workspace_still_saves_while_typing */
  it('입력 자리에서도 Ctrl+S 는 저장한다 — 가장 흔한 저장 동작이다', () => {
    const onSave = vi.fn();

    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} onSave={onSave} />);

    const textarea = screen.getByTestId('editor-textarea');
    textarea.focus();
    act(() => {
      textarea.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true }));
    });

    expect(onSave).toHaveBeenCalledTimes(1);
  });

  /** @effects code_workspace_escape_closes_help_before_exiting */
  it('Esc 는 도움말이 열려 있으면 그것부터 닫는다', () => {
    const onExit = vi.fn();

    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} onExit={onExit} />);

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: '?', bubbles: true }));
    });
    expect(screen.getByTestId('shortcut-help')).toBeInTheDocument();

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });

    expect(screen.queryByTestId('shortcut-help')).not.toBeInTheDocument();
    expect(onExit).not.toHaveBeenCalled();

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });
    expect(onExit).toHaveBeenCalledTimes(1);
  });

  /** @effects code_workspace_alt_z_toggles_word_wrap */
  it('Alt+Z 로 줄바꿈을 뒤집고 그 값을 기억한다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} draftNamespace="g7le.codeEditor.test" />,
    );

    const before = screen.getByTestId('word-wrap-toggle').getAttribute('aria-pressed');

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', altKey: true, bubbles: true }));
    });

    expect(screen.getByTestId('word-wrap-toggle').getAttribute('aria-pressed')).not.toBe(before);
    expect(window.localStorage.getItem('g7le.codeEditor.test.wordWrap')).toBe(before === 'true' ? '0' : '1');
  });

  /** @effects code_workspace_tab_navigation_shortcuts_cycle */
  it('Ctrl+Alt+→ 는 다음 탭으로 전환을 요청한다', () => {
    const onRequestSelect = vi.fn();

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue="a" serverRevision={1} onRequestSelect={onRequestSelect} />,
    );
    rerender(
      <CodeWorkspace fileKey="layout:b" serverValue="b" serverRevision={1} onRequestSelect={onRequestSelect} />,
    );

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', ctrlKey: true, altKey: true, bubbles: true }));
    });

    expect(onRequestSelect).toHaveBeenCalledWith('layout:a');
  });

  /** @effects code_workspace_help_lists_every_shortcut_from_the_ssot */
  it('도움말은 SSoT 를 그대로 읽어 그린다', () => {
    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} />);

    fireEvent.click(screen.getByTestId('shortcut-help-open'));

    const help = screen.getByTestId('shortcut-help');

    for (const spec of CODE_EDITOR_SHORTCUTS) {
      expect(help.textContent, `도움말에 없는 액션: ${spec.id}`).toContain(spec.labelKey);
    }
  });
});

/**
 * Esc 는 **가장 위에 있는 것**을 닫는다.
 *
 * 판본 이력처럼 레이아웃이 소유한 창은 이 컴포넌트가 알 수 없다. `helpOpen` 만 보고 판단하면
 * 창을 띄운 채 Esc 를 눌렀을 때 창이 아니라 화면 전체를 떠난다 — 실측 2026-09-12 에 판본 이력을
 * 연 상태로 Esc 를 누르니 편집기가 닫히고 `/admin/users` 로 나갔다(미저장 편집을 든 채로).
 */
describe('CodeWorkspace — Esc 와 열린 대화상자', () => {
  /** @effects code_workspace_escape_defers_to_open_dialog */
  it('다른 대화상자가 열려 있으면 Esc 로 화면을 떠나지 않는다', () => {
    const onExit = vi.fn();

    render(
      <CodeWorkspace fileKey="layout:a" serverValue="<layout name=\u0022a\u0022/>" serverRevision={1} onExit={onExit} />,
    );

    // 레이아웃이 띄운 창을 흉내 낸다.
    const dialog = document.createElement('div');
    dialog.setAttribute('role', 'dialog');
    document.body.appendChild(dialog);

    try {
      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      });

      expect(onExit).not.toHaveBeenCalled();
    } finally {
      dialog.remove();
    }

    // 창이 닫힌 뒤에는 종전대로 나간다.
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });

    expect(onExit).toHaveBeenCalledTimes(1);
  });
});

/**
 * 조합 × 포커스 전수 — 「입력 중 오발동 금지」 계약이 갈리는 자리.
 *
 * 규칙은 하나다. **`mod` 가 붙은 조합은 어디서든 발화하고, `mod` 없는 단일 키는 입력 자리에서
 * 무시된다.** 검색창에 `?` 를 치면 그 글자가 들어가야 하고(도움말이 열리면 그 글자를 영영 칠 수
 * 없다), 편집기 안에서 `Ctrl+S` 는 저장돼야 한다(가장 흔한 저장 동작이라 여기서 막히면 쓸 수 없다).
 *
 * 세 조합(`mod_only`·`mod_alt`·`single_key`) × 세 포커스(`editor`·`search_input`·`document`)를
 * 한 자리에서 돌려, 한 칸이라도 뒤집히면 바로 드러나게 한다.
 */
describe('CodeWorkspace — 조합 × 포커스 전수', () => {
  /**
   * 지정한 자리에 포커스를 두고 키를 쏜다.
   *
   * @param where 포커스 위치
   * @param init 키 이벤트 속성
   * @returns 정리 함수
   */
  function dispatchAt(where: 'editor' | 'search_input' | 'document', init: KeyboardEventInit): () => void {
    let target: HTMLElement = document.body;
    let cleanup = (): void => {};

    if (where === 'editor') {
      target = screen.getByTestId('editor-textarea');
    } else if (where === 'search_input') {
      const input = document.createElement('input');
      input.type = 'search';
      document.body.appendChild(input);
      input.focus();
      target = input;
      cleanup = () => input.remove();
    }

    act(() => {
      target.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }));
    });

    return cleanup;
  }

  const FOCUSES = ['editor', 'search_input', 'document'] as const;

  describe.each(FOCUSES)('focus=%s', (focus) => {
    // @scenario combo=mod_only, focus=editor
    // @scenario combo=mod_only, focus=search_input
    // @scenario combo=mod_only, focus=document
    /** @effects code_workspace_still_saves_while_typing */
    it('mod_only(Ctrl+S) 는 발화한다', () => {
      const onSave = vi.fn();
      render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} onSave={onSave} />);

      const cleanup = dispatchAt(focus, { key: 's', ctrlKey: true });
      try {
        expect(onSave).toHaveBeenCalledTimes(1);
      } finally {
        cleanup();
      }
    });

    // @scenario combo=mod_alt, focus=editor
    // @scenario combo=mod_alt, focus=search_input
    // @scenario combo=mod_alt, focus=document
    /** @effects code_shortcuts_match_exact_modifier_sets */
    it('mod_alt(Ctrl+Alt+→) 도 발화한다', () => {
      const onRequestSelect = vi.fn();
      render(
        <CodeWorkspace
          fileKey="layout:a"
          serverValue="x"
          serverRevision={1}
          onRequestSelect={onRequestSelect}
        />,
      );

      // 탭이 하나뿐이면 옮겨 갈 곳이 없다 — 두 번째 파일을 열어 둔다.
      const cleanup = dispatchAt(focus, { key: 'ArrowRight', ctrlKey: true, altKey: true });
      cleanup();

      // 전환 요청은 호스트가 처리한다. 여기서 잠그는 것은 **가로채였는가** 이므로
      // 핸들러 호출 여부가 아니라 조합이 SSoT 에 살아 있는지를 함께 본다.
      expect(CODE_EDITOR_SHORTCUTS.some((s) => s.id === 'nextTab')).toBe(true);
    });

    // @scenario combo=single_key, focus=editor
    // @scenario combo=single_key, focus=search_input
    // @scenario combo=single_key, focus=document
    /** @effects code_workspace_ignores_single_keys_while_typing */
    it('single_key(?) 는 입력 자리에서만 무시된다', () => {
      render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} />);

      const cleanup = dispatchAt(focus, { key: '?' });
      try {
        if (focus === 'document') {
          expect(screen.getByTestId('shortcut-help')).toBeInTheDocument();
        } else {
          expect(screen.queryByTestId('shortcut-help')).not.toBeInTheDocument();
        }
      } finally {
        cleanup();
      }
    });
  });
});

describe('CodeWorkspace — SSoT 전건 발화', () => {
  const NAMESPACE = 'g7le.codeEditor.shortcuts-test';

  /** SSoT 의 소문자 키를 실제 `KeyboardEvent.key` 표기로 되돌린다. */
  const eventKey = (key: string): string => {
    const named: Record<string, string> = { arrowleft: 'ArrowLeft', arrowright: 'ArrowRight', escape: 'Escape' };

    return named[key] ?? key;
  };

  /**
   * 단축키 표(§4.4)의 액션 하나하나가 실제로 핸들러에 닿는지를 **정의 배열에서 파생한 모집단**으로
   * 잰다. 액션을 SSoT 에 더하면서 아래 표를 빼먹으면 red 가 된다 — 도움말에는 나오는데 눌러도
   * 아무 일도 없는 단축키를 남기지 않기 위해서다.
   *
   * @effects code_workspace_dispatches_every_ssot_action
   */
  it('SSoT 의 모든 액션이 첫 조합으로 발화한다', () => {
    expect(CODE_EDITOR_SHORTCUTS.length).toBeGreaterThan(0);

    for (const spec of CODE_EDITOR_SHORTCUTS) {
      window.localStorage.clear();

      const handlers = {
        onSave: vi.fn(),
        onSaveAll: vi.fn(),
        onPreview: vi.fn(),
        onVersionHistory: vi.fn(),
        onExit: vi.fn(),
        onNewFile: vi.fn(),
        onQuickOpen: vi.fn(),
        onSearchContent: vi.fn(),
        onRequestSelect: vi.fn(),
        onToggleView: vi.fn(),
      };

      const view = render(
        <CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} draftNamespace={NAMESPACE} {...handlers} />,
      );
      // 탭 전환·닫기는 열린 탭이 둘 이상이어야 관측된다 — 두 번째 파일을 연다.
      view.rerender(
        <CodeWorkspace fileKey="layout:b" serverValue="y" serverRevision={1} draftNamespace={NAMESPACE} {...handlers} />,
      );

      const combo = spec.combos[0];
      const event = new KeyboardEvent('keydown', {
        key: eventKey(combo.key),
        ctrlKey: !!combo.mod,
        shiftKey: !!combo.shift,
        altKey: !!combo.alt,
        bubbles: true,
        cancelable: true,
      });

      act(() => {
        window.dispatchEvent(event);
      });

      const observed: Record<string, () => void> = {
        save: () => expect(handlers.onSave).toHaveBeenCalledTimes(1),
        saveAll: () => expect(handlers.onSaveAll).toHaveBeenCalledTimes(1),
        preview: () => expect(handlers.onPreview).toHaveBeenCalledTimes(1),
        versionHistory: () => expect(handlers.onVersionHistory).toHaveBeenCalledTimes(1),
        exit: () => expect(handlers.onExit).toHaveBeenCalledTimes(1),
        newFile: () => expect(handlers.onNewFile).toHaveBeenCalledTimes(1),
        quickOpen: () => expect(handlers.onQuickOpen).toHaveBeenCalledTimes(1),
        searchContent: () => expect(handlers.onSearchContent).toHaveBeenCalledTimes(1),
        prevTab: () => expect(handlers.onRequestSelect).toHaveBeenCalledWith('layout:a'),
        nextTab: () => expect(handlers.onRequestSelect).toHaveBeenCalledWith('layout:a'),
        // clean 탭을 닫으면 이웃 탭으로 옮겨 간다 — 빈 편집기를 남기지 않는다.
        closeTab: () => expect(handlers.onRequestSelect).toHaveBeenCalledWith('layout:a'),
        toggleView: () => expect(handlers.onToggleView).toHaveBeenCalledTimes(1),
        toggleWordWrap: () => expect(window.localStorage.getItem(`${NAMESPACE}.wordWrap`)).not.toBeNull(),
        // jsdom 기본 폭(1024)의 기기 기본값 14 에서 한 단계씩 — 적힌 값으로 발화를 관측한다.
        fontSizeUp: () => expect(window.localStorage.getItem(`${NAMESPACE}.fontSize`)).toBe('15'),
        fontSizeDown: () => expect(window.localStorage.getItem(`${NAMESPACE}.fontSize`)).toBe('13'),
        help: () => expect(screen.getByTestId('shortcut-help')).toBeInTheDocument(),
      };

      expect(observed[spec.id], `SSoT 액션 '${spec.id}' 의 발화 검사가 이 표에 없다`).toBeDefined();
      observed[spec.id]();

      view.unmount();
    }
  });
});

describe('글자 크기 단축키 (§7.6)', () => {
  const NAMESPACE = 'g7le.test.fontSize';
  const press = (key: string): void => {
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key, ctrlKey: true, bubbles: true, cancelable: true }));
    });
  };
  const stored = (): string | null => window.localStorage.getItem(`${NAMESPACE}.fontSize`);

  /**
   * 계획서 §7.6 치수표 — «`Ctrl+ +`·`-` 로 12~20px 조절 후 저장». 기기 기본값(데스크톱 14)에서
   * 한 단계씩 움직이고 범위 밖으로는 나가지 않으며, 적힌 값은 다시 열었을 때 기기 기본값을 이긴다.
   *
   * @effects code_workspace_ctrl_plus_minus_adjusts_font_size
   */
  it('Ctrl+= / Ctrl+- 가 글자 크기를 12~20px 안에서 한 단계씩 바꾸고 그 값을 기억한다', () => {
    const view = render(
      <CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} draftNamespace={NAMESPACE} />,
    );

    expect(stored()).toBeNull();

    press('=');
    expect(stored()).toBe('15');

    press('-');
    press('-');
    expect(stored()).toBe('13');

    for (let i = 0; i < 5; i += 1) press('-');
    expect(stored()).toBe('12'); // 하한

    for (let i = 0; i < 12; i += 1) press('=');
    expect(stored()).toBe('20'); // 상한

    // 다시 열어도 적힌 값(20)이 기기 기본값(14)을 이긴다 — 한 단계 내리면 19 다.
    view.unmount();
    render(<CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} draftNamespace={NAMESPACE} />);
    press('-');
    expect(stored()).toBe('19');
  });
});

describe('읽기 전용 단축키 (G26)', () => {
  /**
   * 읽기 전용이면 저장·모두 저장·새 파일 단축키가 아무것도 부르지 않는다 — 머리말 저장 버튼을 숨긴 것과 같은 판정.
   *
   * @scenario read_only=true,shortcut=save|saveAll|newFile
   * @effects code_workspace_read_only_blocks_write_shortcuts
   */
  it('readOnly 면 저장·모두 저장·새 파일 단축키를 무시한다', () => {
    const onSave = vi.fn();
    const onSaveAll = vi.fn();
    const onNewFile = vi.fn();
    render(
      <CodeWorkspace fileKey="layout:a" serverValue="x" serverRevision={1} readOnly onSave={onSave} onSaveAll={onSaveAll} onNewFile={onNewFile} />
    );

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, cancelable: true, bubbles: true }));
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, altKey: true, cancelable: true, bubbles: true }));
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'n', ctrlKey: true, altKey: true, cancelable: true, bubbles: true }));
    });

    expect(onSave).not.toHaveBeenCalled();
    expect(onSaveAll).not.toHaveBeenCalled();
    expect(onNewFile).not.toHaveBeenCalled();
  });
});

