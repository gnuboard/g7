// e2e:allow 편집기 내부 대화상자 — 화면 검증은 이력 문서의 브라우저 실측(커스텀 자산 이름변경·삭제·나가기)으로 한다.
/**
 * 레이아웃 편집기 확인·입력 대화상자 (P18, engine-v1.78.7)
 *
 * 편집기 곳곳(나가기·템플릿 전환·커스텀 자산 이름변경·삭제·버전 복원·번역/첨부 삭제)이 브라우저 기본
 * `window.confirm`/`window.prompt` 를 써서 사이트와 다른 모양의 창이 떴다. 편집기 모달 스택 위에 뜨는
 * 대화상자로 바꾼다.
 *
 * 잠그는 계약:
 *  - 대화상자는 role=alertdialog, 접근 가능한 이름 = 제목, 설명 = 본문
 *  - 처음 초점: 입력 대화상자는 입력칸(기존 값 전체 선택), 위험 확인은 [취소], 그 밖은 [확인]
 *  - [확인] → true/입력값, [취소]·Esc·백드롭 → false/null, 닫히면 연 요소로 초점이 돌아간다
 *  - Tab 은 대화상자 안에서 돈다
 *  - 편집기 소스(테스트·대화상자 폴백 제외)에 window.confirm/prompt 호출이 남지 않는다
 */

import React, { useState } from 'react';
import { readdirSync, readFileSync, statSync } from 'fs';
import { join, resolve } from 'path';
import { describe, expect, it, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { EditorModalProvider, EditorModalRoot } from '../../EditorModalContext';
import { useEditorDialog } from '../../components/EditorDialog';

const t = (key: string): string => key;

function Harness({ mode, danger }: { mode: 'confirm' | 'prompt'; danger?: boolean }): React.ReactElement {
  const dialog = useEditorDialog(t);
  const [result, setResult] = useState<string>('(none)');

  return (
    <div>
      <button
        type="button"
        data-testid="opener"
        onClick={async () => {
          if (mode === 'confirm') {
            const ok = await dialog.confirm({ title: '삭제할까요', message: '되돌릴 수 없습니다.', confirmLabel: '삭제', danger });
            setResult(String(ok));
          } else {
            const value = await dialog.prompt({ title: '이름변경', message: '새 경로를 입력하세요.', defaultValue: 'a/b.css' });
            setResult(value === null ? 'null' : value);
          }
        }}
      >
        open
      </button>
      <span data-testid="result">{result}</span>
    </div>
  );
}

function mount(node: React.ReactElement): void {
  render(
    <EditorModalProvider t={t}>
      {node}
      <EditorModalRoot />
    </EditorModalProvider>,
  );
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe('편집기 확인 대화상자', () => {
  // @scenario dialog_kind=confirm
  // @effects dialog_replaces_native_confirm_and_prompt, dialog_exposes_name_and_description, dialog_returns_focus_to_opener
  it('브라우저 confirm 대신 이름·설명이 있는 alertdialog 를 띄우고 [확인] 이면 true', async () => {
    const native = vi.spyOn(window, 'confirm').mockImplementation(() => {
      throw new Error('window.confirm 을 부르면 안 된다');
    });
    mount(<Harness mode="confirm" />);
    const opener = screen.getByTestId('opener');
    opener.focus();
    fireEvent.click(opener);

    const dialog = await screen.findByRole('alertdialog');
    expect(dialog.getAttribute('aria-label')).toBe('삭제할까요');
    const describedBy = dialog.getAttribute('aria-describedby');
    expect(describedBy).toBeTruthy();
    expect(document.getElementById(describedBy as string)?.textContent).toBe('되돌릴 수 없습니다.');
    expect(document.activeElement?.textContent).toBe('삭제');

    fireEvent.click(screen.getByRole('button', { name: '삭제' }));

    await waitFor(() => expect(screen.getByTestId('result').textContent).toBe('true'));
    expect(screen.queryByRole('alertdialog')).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(opener));
    expect(native).not.toHaveBeenCalled();
  });

  // @scenario dialog_kind=confirm
  // @effects dialog_cancel_paths_resolve_cancel, dialog_returns_focus_to_opener
  it('Esc 는 취소(false)이고 초점이 연 버튼으로 돌아간다', async () => {
    mount(<Harness mode="confirm" />);
    const opener = screen.getByTestId('opener');
    opener.focus();
    fireEvent.click(opener);
    await screen.findByRole('alertdialog');

    fireEvent.keyDown(document.activeElement as Element, { key: 'Escape' });

    await waitFor(() => expect(screen.getByTestId('result').textContent).toBe('false'));
    expect(screen.queryByRole('alertdialog')).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(opener));
  });

  // @scenario dialog_kind=confirm
  // @effects dialog_initial_focus_follows_kind, dialog_traps_tab_focus
  it('위험 확인은 처음 초점이 [취소]이고 Tab 은 대화상자 안에서 돈다', async () => {
    mount(<Harness mode="confirm" danger />);
    fireEvent.click(screen.getByTestId('opener'));
    await screen.findByRole('alertdialog');

    const cancel = screen.getByRole('button', { name: 'layout_editor.dialog.cancel' });
    const ok = screen.getByRole('button', { name: '삭제' });
    expect(document.activeElement).toBe(cancel);

    fireEvent.keyDown(document.activeElement as Element, { key: 'Tab' });
    expect(document.activeElement).toBe(ok);
    fireEvent.keyDown(document.activeElement as Element, { key: 'Tab' });
    expect(document.activeElement).toBe(cancel);
    fireEvent.keyDown(document.activeElement as Element, { key: 'Tab', shiftKey: true });
    expect(document.activeElement).toBe(ok);

    fireEvent.click(cancel);
    await waitFor(() => expect(screen.getByTestId('result').textContent).toBe('false'));
  });

  // @scenario dialog_kind=confirm
  // @effects dialog_cancel_paths_resolve_cancel
  it('백드롭을 누르면 취소(false)', async () => {
    mount(<Harness mode="confirm" />);
    fireEvent.click(screen.getByTestId('opener'));
    await screen.findByRole('alertdialog');

    fireEvent.click(document.querySelector('.g7le-modal-backdrop') as HTMLElement);

    await waitFor(() => expect(screen.getByTestId('result').textContent).toBe('false'));
  });
});

describe('편집기 입력 대화상자', () => {
  // @scenario dialog_kind=prompt
  // @effects dialog_replaces_native_confirm_and_prompt, dialog_initial_focus_follows_kind
  it('기존 값을 고른 입력칸에 초점, Enter 면 입력값을 돌려준다', async () => {
    const native = vi.spyOn(window, 'prompt').mockImplementation(() => {
      throw new Error('window.prompt 를 부르면 안 된다');
    });
    mount(<Harness mode="prompt" />);
    fireEvent.click(screen.getByTestId('opener'));
    await screen.findByRole('alertdialog');

    const input = screen.getByRole('textbox', { name: '새 경로를 입력하세요.' }) as HTMLInputElement;
    expect(document.activeElement).toBe(input);
    expect(input.value).toBe('a/b.css');
    expect(input.selectionStart).toBe(0);
    expect(input.selectionEnd).toBe('a/b.css'.length);

    fireEvent.change(input, { target: { value: 'a/c.css' } });
    fireEvent.keyDown(input, { key: 'Enter' });

    await waitFor(() => expect(screen.getByTestId('result').textContent).toBe('a/c.css'));
    expect(native).not.toHaveBeenCalled();
  });

  // @scenario dialog_kind=prompt
  // @effects dialog_cancel_paths_resolve_cancel
  it('[취소]면 null', async () => {
    mount(<Harness mode="prompt" />);
    fireEvent.click(screen.getByTestId('opener'));
    await screen.findByRole('alertdialog');

    fireEvent.click(screen.getByRole('button', { name: 'layout_editor.dialog.cancel' }));

    await waitFor(() => expect(screen.getByTestId('result').textContent).toBe('null'));
  });
});

describe('편집기 소스에 브라우저 기본 대화상자가 남지 않는다', () => {
  // @scenario dialog_kind=confirm
  // @effects editor_sources_have_no_native_dialog_calls
  it('layout-editor 소스(테스트·대화상자 폴백 제외)에 window.confirm/prompt 호출 0', () => {
    const root = resolve(__dirname, '../..');
    const offenders: string[] = [];
    const walk = (dir: string): void => {
      for (const name of readdirSync(dir)) {
        const full = join(dir, name);
        if (statSync(full).isDirectory()) {
          if (name === '__tests__' || name === 'node_modules') continue;
          walk(full);
          continue;
        }
        if (!/\.tsx?$/.test(name) || name === 'EditorDialog.tsx') continue;
        readFileSync(full, 'utf8').split('\n').forEach((line, i) => {
          if (/window\.(confirm|prompt)\s*\(/.test(line)) offenders.push(`${full.slice(root.length + 1)}:${i + 1}`);
        });
      }
    };
    walk(root);
    expect(offenders).toEqual([]);
  });
});

// act 경고 억제용 — 미사용 import 방지
void act;
