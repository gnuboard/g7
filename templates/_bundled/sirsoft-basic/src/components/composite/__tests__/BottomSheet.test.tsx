/**
 * BottomSheet 컴포넌트 테스트
 *
 * @description 화면 아래에서 올라오는 시트가 닫혀 있을 때 DOM 에 없고, 열리면 대화 상자로 동작하는지
 *   (닫기 세 갈래·초점 이동·복귀·가둠·본문 스크롤 잠금) 잠근다.
 *
 * @scenario-manifest tests/scenarios/shop-mobile-buy-bar.yaml
 */

import React, { useState } from 'react';
import { act, fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BottomSheet } from '../BottomSheet';

describe('BottomSheet', () => {
  let originalG7Core: any;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = { ...originalG7Core, t: (k: string) => k };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    document.body.className = '';
    document.body.innerHTML = '';
  });

  const dialog = () => document.querySelector('[role="dialog"]') as HTMLElement | null;

  // @scenario case=c07
  // @effects sheet_absent_when_closed
  it('닫혀 있으면 DOM 에 없다', () => {
    render(
      <BottomSheet open={false} title="옵션 선택">
        <button type="button">안쪽</button>
      </BottomSheet>,
    );

    expect(dialog()).toBeNull();
    expect(document.body.classList.contains('g7-bottom-sheet-open')).toBe(false);
  });

  // @scenario case=c08
  // @effects sheet_is_modal_dialog_on_narrow_screens
  it('열리면 제목으로 이름 붙은 대화 상자이고 좁은 화면 전용이다(다크 짝)', () => {
    render(
      <BottomSheet open title="옵션 선택">
        <button type="button">안쪽</button>
      </BottomSheet>,
    );
    const el = dialog()!;

    expect(el.getAttribute('aria-modal')).toBe('true');
    const labelledBy = el.getAttribute('aria-labelledby')!;
    expect(document.getElementById(labelledBy)?.textContent).toBe('옵션 선택');
    expect(el.className).toMatch(/bg-white dark:bg-gray-800/);
    expect(el.className).toContain('rounded-t-2xl');
    expect(el.className).toContain('max-h-[85vh]');
    const root = document.querySelector('[data-bottom-sheet]') as HTMLElement;
    expect(root.className).toContain('md:hidden');
    expect(root.className).toContain('z-40');
    // 움직임을 줄이라는 설정이면 미끄럼 없이
    expect(el.className).toContain('motion-reduce:animate-none');
  });

  // @scenario case=c09
  // @effects sheet_closes_three_ways
  it('Esc·배경 누름·닫기 단추가 모두 닫기를 부른다 — 시트 안을 눌러서는 닫히지 않는다', () => {
    const onClose = vi.fn();
    render(
      <BottomSheet open title="옵션 선택" onClose={onClose}>
        <button type="button" data-testid="inside">
          안쪽
        </button>
      </BottomSheet>,
    );

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);

    fireEvent.click(document.querySelector('[data-bottom-sheet-backdrop]')!);
    expect(onClose).toHaveBeenCalledTimes(2);

    fireEvent.click(document.querySelector('[data-bottom-sheet-close]')!);
    expect(onClose).toHaveBeenCalledTimes(3);

    fireEvent.click(document.querySelector('[data-testid="inside"]')!);
    expect(onClose).toHaveBeenCalledTimes(3);
  });

  // @scenario case=c10
  // @effects sheet_moves_and_restores_focus
  it('열면 시트 안으로 초점이 가고 닫으면 연 단추로 돌아온다', () => {
    const Harness = () => {
      const [open, setOpen] = useState(false);
      return (
        <div>
          <button type="button" data-testid="opener" onClick={() => setOpen(true)}>
            열기
          </button>
          <BottomSheet open={open} title="옵션 선택" onClose={() => setOpen(false)}>
            <button type="button" data-testid="first">
              첫 칸
            </button>
          </BottomSheet>
        </div>
      );
    };
    const { getByTestId } = render(<Harness />);
    const opener = getByTestId('opener');
    opener.focus();

    act(() => {
      fireEvent.click(opener);
    });
    expect(dialog()!.contains(document.activeElement)).toBe(true);

    act(() => {
      fireEvent.keyDown(document, { key: 'Escape' });
    });
    expect(dialog()).toBeNull();
    expect(document.activeElement).toBe(opener);
  });

  // @scenario case=c11
  // @effects sheet_traps_focus
  it('Tab 이 시트 밖으로 나가지 않는다', () => {
    render(
      <BottomSheet open title="옵션 선택">
        <button type="button" data-testid="a">
          가
        </button>
        <button type="button" data-testid="b">
          나
        </button>
      </BottomSheet>,
    );
    const focusables = Array.from(dialog()!.querySelectorAll('button')) as HTMLElement[];
    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    last.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(first);

    first.focus();
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
    expect(document.activeElement).toBe(last);
  });

  // @scenario case=c12
  // @effects sheet_locks_body_scroll_while_open
  it('열려 있는 동안 본문 스크롤을 잠그고 닫히면 푼다', () => {
    const { rerender, unmount } = render(
      <BottomSheet open title="옵션 선택">
        <span>본문</span>
      </BottomSheet>,
    );
    expect(document.body.classList.contains('g7-bottom-sheet-open')).toBe(true);

    rerender(
      <BottomSheet open={false} title="옵션 선택">
        <span>본문</span>
      </BottomSheet>,
    );
    expect(document.body.classList.contains('g7-bottom-sheet-open')).toBe(false);

    rerender(
      <BottomSheet open title="옵션 선택">
        <span>본문</span>
      </BottomSheet>,
    );
    unmount();
    expect(document.body.classList.contains('g7-bottom-sheet-open')).toBe(false);
  });
});
